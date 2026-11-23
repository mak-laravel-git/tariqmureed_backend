<?php

use App\Enums\RoleEnum;
use App\Models\Article;
use App\Models\SyncRun;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function actingAsDashboardUser(RoleEnum $role): void
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);
}

it('requires a token and the dashboard permission', function () {
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

    actingAsDashboardUser(RoleEnum::USER);

    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

it('shows real counts, recent syncs and system status to an admin', function () {
    config(['sportmonks.token' => 'secret-token-123']);

    Article::factory()->create();
    Article::factory()->draft()->create();
    Article::factory()->create(['status' => Article::PENDING, 'published_at' => null]);
    SyncRun::factory()->create(['records_synced' => 42]);

    actingAsDashboardUser(RoleEnum::ADMIN);

    $data = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
    $stats = collect($data['stats'])->pluck('value', 'label');

    expect($stats->keys()->all())->toBe(['Total articles', 'Published', 'Drafts', 'Pending approval', 'Categories', 'Authors', 'Media assets', 'Active staff'])
        ->and($stats['Total articles'])->toBe('3')
        ->and($stats['Published'])->toBe('1')
        ->and($stats['Drafts'])->toBe('1')
        ->and($stats['Pending approval'])->toBe('1')
        ->and(collect($data['activity'])->firstWhere('actor', 'SportMonks sync'))->toMatchArray(['action' => 'completed', 'target' => 'Fixtures & results · 42 records'])
        ->and(collect($data['system'])->firstWhere('name', 'SportMonks API')['status'])->toBe('Connected')
        ->and(array_column($data['system'], 'name'))->toBe(['SportMonks API', 'Media storage', 'Public website', 'Advertising']);
});

it('reports the SportMonks connection as offline without a token', function () {
    config(['sportmonks.token' => null]);

    actingAsDashboardUser(RoleEnum::SUPER_ADMIN);

    $system = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.system');

    expect(collect($system)->firstWhere('name', 'SportMonks API'))->toMatchArray(['status' => 'Offline']);
});
