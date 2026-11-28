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

function signInForLiveCentre(RoleEnum $role = RoleEnum::ADMIN): void
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);
}

function priorityLeague(int $id, string $name, string $category = 'Domestic'): League
{
    return League::factory()->create(['id' => $id, 'name' => $name, 'category' => $category, 'format' => 'T20']);
}

/** A match of the league starting so many hours from now (negative: in the past). */
function priorityMatch(string $slug, League $league, int $hours, string $status = 'NS'): void
{
    Fixture::factory()->create(['slug' => $slug, 'league_id' => $league->id, 'status' => $status, 'starting_at' => now()->addHours($hours)]);
}

/**
 * @return array<int, string>
 */
function prioritySlugs(string $query): array
{
    return test()->getJson("/api/v1/matches?{$query}")->assertOk()->json('data.*.slug');
}

it('lists upcoming matches by date and time, whatever their competition', function () {
    signInForLiveCentre();
    $international = priorityLeague(1, 'One Day International', 'International');
    $league = priorityLeague(2, 'Big Bash League');
    priorityMatch('country-in-two-days', $international, 48);
    priorityMatch('league-tomorrow', $league, 26);
    priorityMatch('country-next-month', $international, 24 * 30);
    priorityMatch('league-today', $league, 2);
    $this->putJson('/api/v1/admin/live-centre', ['leagues' => [1]])->assertOk();
    $expected = ['league-today', 'league-tomorrow', 'country-in-two-days', 'country-next-month'];

    expect(prioritySlugs('state=upcoming'))->toBe($expected)
        ->and(prioritySlugs('state=upcoming&order=priority'))->toBe($expected);
});

it('puts international results of the past week before newer league results', function () {
    $international = priorityLeague(1, 'One Day International', 'International');
    $league = priorityLeague(2, 'Big Bash League');
    priorityMatch('league-today', $league, -3, 'Finished');
    priorityMatch('country-older', $international, -72, 'Finished');
    priorityMatch('country-newer', $international, -48, 'Finished');

    expect(prioritySlugs('state=result&order=priority'))->toBe(['country-newer', 'country-older', 'league-today'])
        ->and(prioritySlugs('state=result&order=priority&limit=1'))->toBe(['country-newer']);
});

it('leaves results older than a week in order of time, after the recent ones', function () {
    $international = priorityLeague(1, 'One Day International', 'International');
    $league = priorityLeague(2, 'Big Bash League');
    priorityMatch('country-last-month', $international, -24 * 30, 'Finished');
    priorityMatch('league-ten-days-ago', $league, -24 * 10, 'Finished');
    priorityMatch('league-today', $league, -3, 'Finished');

    expect(prioritySlugs('state=result&order=priority'))->toBe(['league-today', 'league-ten-days-ago', 'country-last-month'])
        ->and(prioritySlugs('state=result&order=priority&limit=2'))->toBe(['league-today', 'league-ten-days-ago']);
});

it('puts international matches first among the live ones', function () {
    $international = priorityLeague(1, 'Test Championship', 'International');
    $league = priorityLeague(2, 'Big Bash League');
    priorityMatch('league-live', $league, -5, '1st Innings');
    priorityMatch('country-live', $international, -1, 'Lunch');

    expect(prioritySlugs('state=live&order=priority'))->toBe(['country-live', 'league-live']);
});

it('follows the order arranged in the dashboard before anything else', function () {
    signInForLiveCentre();
    $international = priorityLeague(1, 'One Day International', 'International');
    $bigBash = priorityLeague(2, 'Big Bash League');
    $shield = priorityLeague(3, 'Sheffield Shield');
    $other = priorityLeague(4, 'Plunket Shield');
    priorityMatch('other', $other, -1, 'Finished');
    priorityMatch('country', $international, -2, 'Finished');
    priorityMatch('big-bash', $bigBash, -3, 'Finished');
    priorityMatch('shield', $shield, -4, 'Finished');

    $this->putJson('/api/v1/admin/live-centre', ['leagues' => [3, 2]])
        ->assertOk()
        ->assertJsonPath('data.selected.0.name', 'Sheffield Shield')
        ->assertJsonPath('data.selected.1.name', 'Big Bash League');

    expect(prioritySlugs('state=result&order=priority'))->toBe(['shield', 'big-bash', 'country', 'other']);

    $this->assertDatabaseHas('activity_logs', ['subject' => 'series', 'target' => 'Live Match Center order']);
});

it('keeps the other lists in order of time', function () {
    $international = priorityLeague(1, 'One Day International', 'International');
    $league = priorityLeague(2, 'Big Bash League');
    priorityMatch('league-today', $league, -3, 'Finished');
    priorityMatch('country-older', $international, -48, 'Finished');

    expect(prioritySlugs('state=result'))->toBe(['league-today', 'country-older']);
});

it('lists the other competitions for arranging, those with matches around now first', function () {
    signInForLiveCentre();
    priorityLeague(1, 'Asia Cup', 'International');
    $bigBash = priorityLeague(2, 'Big Bash League');
    $odi = priorityLeague(3, 'One Day International', 'International');
    priorityMatch('big-bash', $bigBash, 3);
    priorityMatch('country', $odi, -48, 'Finished');
    priorityMatch('country-next-month', $odi, 24 * 30);

    $data = $this->getJson('/api/v1/admin/live-centre')->assertOk()->json('data');

    expect($data['windowDays'])->toBe(7)
        ->and($data['selected'])->toBe([])
        ->and(collect($data['available'])->pluck('name')->all())->toBe(['One Day International', 'Big Bash League', 'Asia Cup'])
        ->and($data['available'][0])->toBe(['id' => '3', 'name' => 'One Day International', 'detail' => 'International · T20', 'matches' => 1]);
});

it('goes back to internationals first when the list is emptied', function () {
    signInForLiveCentre();
    $international = priorityLeague(1, 'One Day International', 'International');
    $league = priorityLeague(2, 'Big Bash League');
    priorityMatch('league', $league, -1, 'Finished');
    priorityMatch('country', $international, -2, 'Finished');

    $this->putJson('/api/v1/admin/live-centre', ['leagues' => [2]])->assertOk();
    $this->putJson('/api/v1/admin/live-centre', ['leagues' => []])->assertOk()->assertJsonPath('data.selected', []);

    expect(prioritySlugs('state=result&order=priority'))->toBe(['country', 'league']);
});

it('rejects an unusable list of competitions', function (array $payload, string $field) {
    signInForLiveCentre();
    priorityLeague(1, 'Big Bash League');

    $this->putJson('/api/v1/admin/live-centre', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no list' => [[], 'leagues'],
    'a repeated competition' => [['leagues' => [1, 1]], 'leagues.0'],
    'an unknown competition' => [['leagues' => [99]], 'leagues.0'],
]);

it('needs the website settings permission to arrange competitions', function () {
    signInForLiveCentre(RoleEnum::USER);

    $this->getJson('/api/v1/admin/live-centre')->assertForbidden();
    $this->putJson('/api/v1/admin/live-centre', ['leagues' => []])->assertForbidden();
});
