<?php

use App\Enums\RoleEnum;
use App\Models\SyncRun;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SportMonksPayloads as Payloads;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function actingAsRole(RoleEnum $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);

    return $user;
}

it('requires a token for the integration endpoints', function () {
    $this->getJson('/api/v1/integrations/sportmonks')->assertUnauthorized();
    $this->postJson('/api/v1/integrations/sportmonks/sync')->assertUnauthorized();
});

it('forbids users without the integration permissions', function () {
    actingAsRole(RoleEnum::USER);

    $this->getJson('/api/v1/integrations/sportmonks')->assertForbidden();
    $this->postJson('/api/v1/integrations/sportmonks/sync')->assertForbidden();
});

it('reports that SportMonks is not configured', function () {
    config(['sportmonks.token' => null]);
    actingAsRole(RoleEnum::ADMIN);

    $this->getJson('/api/v1/integrations/sportmonks')
        ->assertOk()
        ->assertJsonPath('data.provider', 'SportMonks')
        ->assertJsonPath('data.connection', 'Not configured')
        ->assertJsonPath('data.lastSync', '—')
        ->assertJsonPath('data.jobs', []);
});

it('summarises the latest sync of every target', function () {
    config(['sportmonks.token' => 'secret-token-123']);
    actingAsRole(RoleEnum::ADMIN);
    SyncRun::factory()->create(['target' => 'fixtures', 'records_synced' => 320]);
    SyncRun::factory()->create(['target' => 'teams', 'records_synced' => 273]);
    SyncRun::factory()->create(['target' => 'live', 'status' => SyncRun::FAILED, 'message' => 'SportMonks answered HTTP 500 for livescores.']);
    SyncRun::factory()->create(['target' => 'live', 'status' => SyncRun::SUCCESS, 'records_synced' => 2]);
    SyncRun::factory()->create(['target' => 'standings', 'status' => SyncRun::FAILED, 'message' => 'Boom']);

    $data = $this->getJson('/api/v1/integrations/sportmonks')->assertOk()->json('data');

    expect($data['connection'])->toBe('Error')
        ->and($data['openErrors'])->toBe('1')
        ->and($data['recordsUpdated'])->toBe('595')
        ->and(collect($data['jobs'])->pluck('status', 'id')->all())->toBe(['teams' => 'Success', 'fixtures' => 'Success', 'standings' => 'Failed', 'live' => 'Success'])
        ->and(collect($data['jobs'])->firstWhere('id', 'standings')['detail'])->toContain('Boom')
        ->and(collect($data['jobs'])->firstWhere('id', 'fixtures'))->toMatchArray(['name' => 'Fixtures & results']);
});

it('shows connected once a sync succeeded', function () {
    config(['sportmonks.token' => 'secret-token-123']);
    actingAsRole(RoleEnum::ADMIN);
    SyncRun::factory()->create();

    $this->getJson('/api/v1/integrations/sportmonks')->assertJsonPath('data.connection', 'Connected');
});

it('refuses a manual sync while the token is missing', function () {
    config(['sportmonks.token' => null]);
    actingAsRole(RoleEnum::ADMIN);
    Http::fake();

    $this->postJson('/api/v1/integrations/sportmonks/sync')
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'SPORTMONKS_API_TOKEN'));

    Http::assertNothingSent();
});

it('starts a full sync after responding', function () {
    config(['sportmonks.token' => 'secret-token-123']);
    actingAsRole(RoleEnum::SUPER_ADMIN);
    Payloads::fake([
        'leagues' => ['data' => [Payloads::league()]],
        'venues' => ['data' => []],
        'teams' => ['data' => [Payloads::team(40, 'India', 'IND')]],
        'fixtures' => ['data' => []],
        'standings/season/6' => ['data' => []],
    ]);

    $this->postJson('/api/v1/integrations/sportmonks/sync')->assertAccepted()->assertJsonPath('message', 'Sync started.');

    expect(SyncRun::query()->where('status', SyncRun::SUCCESS)->pluck('target')->all())
        ->toContain('leagues', 'teams', 'fixtures', 'standings');
});

it('registers the sync schedule only through the artisan command', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'sportmonks:sync'));

    expect($events)->toHaveCount(9);
});
