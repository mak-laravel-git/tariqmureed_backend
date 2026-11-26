<?php

use App\Enums\RoleEnum;
use App\Models\Fixture;
use App\Models\League;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function signInForSeries(RoleEnum $role = RoleEnum::ADMIN): void
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);
}

it('lists the series strip with the visible ones in order and the hidden ones apart', function () {
    signInForSeries();

    League::factory()->create(['id' => 1, 'name' => 'Zeta Cup', 'slug' => 'zeta-cup', 'is_featured' => true, 'sort_order' => 1]);
    League::factory()->create(['id' => 2, 'name' => 'Alpha League', 'slug' => 'alpha-league', 'is_featured' => true, 'sort_order' => 2]);
    League::factory()->create(['id' => 3, 'name' => 'Beta Trophy', 'slug' => 'beta-trophy', 'is_featured' => false]);
    Fixture::factory()->create(['league_id' => 1, 'starting_at' => now()->addDays(2)]);

    $data = $this->getJson('/api/v1/admin/competitions/arrangement')->assertOk()->json('data');

    expect(collect($data['visible'])->pluck('name')->all())->toBe(['Zeta Cup', 'Alpha League'])
        ->and(collect($data['hidden'])->pluck('name')->all())->toBe(['Beta Trophy'])
        ->and($data['visible'][0]['status'])->toBe('Upcoming')
        ->and($data['visible'][1]['status'])->toBe('No matches yet');
});

it('saves the order and hides every competition that is not listed', function () {
    signInForSeries();

    foreach ([1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four'] as $id => $name) {
        League::factory()->create(['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'is_featured' => true]);
    }

    $this->putJson('/api/v1/admin/competitions/arrangement', ['visible' => [3, 1]])
        ->assertOk()
        ->assertJsonPath('data.visible.0.name', 'Three')
        ->assertJsonPath('data.visible.1.name', 'One')
        ->assertJsonCount(2, 'data.hidden');

    expect(League::query()->whereKey(3)->value('sort_order'))->toBe(1)
        ->and(League::query()->whereKey(1)->value('sort_order'))->toBe(2)
        ->and(League::query()->whereKey(2)->value('is_featured'))->toBeFalse()
        ->and(League::query()->whereKey(2)->value('sort_order'))->toBeNull();

    $this->putJson('/api/v1/admin/competitions/arrangement', ['visible' => []])->assertOk()->assertJsonCount(0, 'data.visible');
});

it('rejects unknown or repeated competitions', function () {
    signInForSeries();
    League::factory()->create(['id' => 1, 'name' => 'One', 'slug' => 'one']);

    $this->putJson('/api/v1/admin/competitions/arrangement', ['visible' => [1, 1]])->assertUnprocessable()->assertJsonValidationErrors('visible.0');
    $this->putJson('/api/v1/admin/competitions/arrangement', ['visible' => [99]])->assertUnprocessable()->assertJsonValidationErrors('visible.0');
    $this->putJson('/api/v1/admin/competitions/arrangement', [])->assertUnprocessable()->assertJsonValidationErrors('visible');
});

it('needs the website settings permission', function () {
    signInForSeries(RoleEnum::USER);

    $this->getJson('/api/v1/admin/competitions/arrangement')->assertForbidden();
    $this->putJson('/api/v1/admin/competitions/arrangement', ['visible' => []])->assertForbidden();
});

it('shows the website only the visible competitions, in the chosen order, new ones last', function () {
    League::factory()->create(['id' => 1, 'name' => 'Zeta Cup', 'slug' => 'zeta-cup', 'is_featured' => true, 'sort_order' => 1]);
    League::factory()->create(['id' => 2, 'name' => 'Alpha League', 'slug' => 'alpha-league', 'is_featured' => true, 'sort_order' => 2]);
    League::factory()->create(['id' => 3, 'name' => 'Hidden Series', 'slug' => 'hidden-series', 'is_featured' => false]);
    League::factory()->create(['id' => 4, 'name' => 'Brand New Series', 'slug' => 'brand-new-series', 'is_featured' => true]);

    $names = collect($this->getJson('/api/v1/competitions?featured=1')->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Zeta Cup', 'Alpha League', 'Brand New Series']);

    foreach ([1, 3] as $id) {
        Fixture::factory()->create(['league_id' => $id, 'starting_at' => now()->addDay()]);
    }

    $directory = collect($this->getJson('/api/v1/series')->assertOk()->json('data.items'))->pluck('name')->all();
    expect($directory)->toBe(['Zeta Cup']);
});

it('lists series as shown or hidden and filters by those tabs', function () {
    League::factory()->create(['name' => 'Shown League', 'is_featured' => true]);
    League::factory()->create(['name' => 'Hidden League', 'is_featured' => false]);
    signInAs(RoleEnum::ADMIN);

    $this->getJson('/api/v1/admin/competitions')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/admin/competitions?tab=active')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Shown League')->assertJsonPath('data.0.status', 'Shown on website');
    $this->getJson('/api/v1/admin/competitions?tab=archived')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Hidden League')->assertJsonPath('data.0.status', 'Hidden from website');
    $this->getJson('/api/v1/admin/competitions?tab=pending')->assertOk()->assertJsonCount(0, 'data');
});
