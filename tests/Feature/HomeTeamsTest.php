<?php

use App\Enums\RoleEnum;
use App\Models\Fixture;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function signInForHomeTeams(RoleEnum $role = RoleEnum::ADMIN): void
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);
}

function homeTeam(int $id, string $name, bool $national = false): Team
{
    return Team::factory()->create(['id' => $id, 'name' => $name, 'slug' => str($name)->slug(), 'national_team' => $national]);
}

it('picks fifteen teams with fixtures by itself, national sides first, until some are chosen', function () {
    homeTeam(1, 'India', true);
    homeTeam(2, 'Australia', true);
    homeTeam(3, 'TBC');
    homeTeam(4, 'No Matches', true);

    foreach (range(1, 15) as $number) {
        homeTeam(10 + $number, sprintf('Club %02d', $number));
        Fixture::factory()->create(['local_team_id' => 10 + $number, 'visitor_team_id' => 3]);
    }

    Fixture::factory()->create(['local_team_id' => 1, 'visitor_team_id' => 2]);

    expect($this->getJson('/api/v1/teams/home')->assertOk()->json('data.*.name'))
        ->toBe(['Australia', 'India', 'Club 01', 'Club 02', 'Club 03', 'Club 04', 'Club 05', 'Club 06', 'Club 07', 'Club 08', 'Club 09', 'Club 10', 'Club 11', 'Club 12', 'Club 13']);
});

it('shows the website the chosen teams in the chosen order, with or without matches', function () {
    signInForHomeTeams();
    homeTeam(1, 'India', true);
    homeTeam(2, 'Australia', true);
    homeTeam(3, 'Pakistan', true);
    Fixture::factory()->create(['local_team_id' => 1, 'visitor_team_id' => 2]);

    $this->putJson('/api/v1/admin/home-teams', ['teams' => [3, 1]])
        ->assertOk()
        ->assertJsonPath('data.selected.0.name', 'Pakistan')
        ->assertJsonPath('data.selected.0.matches', 0)
        ->assertJsonPath('data.selected.1.matches', 1)
        ->assertJsonPath('data.automatic', []);

    expect($this->getJson('/api/v1/teams/home')->json('data.*.slug'))->toBe(['pakistan', 'india']);

    $this->assertDatabaseHas('activity_logs', ['subject' => 'teams', 'target' => 'Home page teams']);
});

it('goes back to the automatic choice when the list is emptied', function () {
    signInForHomeTeams();
    homeTeam(1, 'India', true);
    homeTeam(2, 'Pakistan', true);
    Fixture::factory()->create(['local_team_id' => 1, 'visitor_team_id' => 1]);

    $this->putJson('/api/v1/admin/home-teams', ['teams' => [2]])->assertOk();
    $this->putJson('/api/v1/admin/home-teams', ['teams' => []])
        ->assertOk()
        ->assertJsonPath('data.selected', [])
        ->assertJsonPath('data.automatic', ['India']);

    expect($this->getJson('/api/v1/teams/home')->json('data.*.slug'))->toBe(['india']);
});

it('lists the other teams for choosing, those with matches first', function () {
    signInForHomeTeams();
    homeTeam(1, 'Afghanistan', true);
    homeTeam(2, 'Sydney Sixers');
    homeTeam(3, 'Perth Scorchers');
    Fixture::factory()->create(['local_team_id' => 2, 'visitor_team_id' => 3]);

    $data = $this->getJson('/api/v1/admin/home-teams')->assertOk()->json('data');

    expect($data['limit'])->toBe(15)
        ->and($data['selected'])->toBe([])
        ->and(collect($data['available'])->pluck('name')->all())->toBe(['Perth Scorchers', 'Sydney Sixers', 'Afghanistan'])
        ->and($data['available'][0])->toBe(['id' => '3', 'name' => 'Perth Scorchers', 'detail' => 'Domestic', 'matches' => 1]);
});

it('accepts fifteen teams and rejects a sixteenth', function () {
    signInForHomeTeams();
    $ids = Team::factory()->count(16)->create()->modelKeys();

    $this->putJson('/api/v1/admin/home-teams', ['teams' => array_slice($ids, 0, 15)])->assertOk()->assertJsonCount(15, 'data.selected');
    $this->putJson('/api/v1/admin/home-teams', ['teams' => $ids])->assertUnprocessable()->assertJsonValidationErrors('teams');
});

it('rejects an unusable list of teams', function (array $payload, string $field) {
    signInForHomeTeams();
    homeTeam(1, 'India', true);

    $this->putJson('/api/v1/admin/home-teams', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no list' => [[], 'teams'],
    'a repeated team' => [['teams' => [1, 1]], 'teams.0'],
    'an unknown team' => [['teams' => [99]], 'teams.0'],
]);

it('needs the website settings permission', function () {
    signInForHomeTeams(RoleEnum::USER);

    $this->getJson('/api/v1/admin/home-teams')->assertForbidden();
    $this->putJson('/api/v1/admin/home-teams', ['teams' => []])->assertForbidden();
});
