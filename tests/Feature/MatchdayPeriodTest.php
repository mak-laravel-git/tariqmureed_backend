<?php

use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-15 12:00:00', 'UTC'));
});

function periodTeam(string $name): Team
{
    return Team::factory()->create(['name' => $name, 'slug' => str($name)->slug()]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function periodMatch(string $slug, string $utc, array $attributes = []): void
{
    Fixture::factory()->create(['slug' => $slug, 'starting_at' => Date::parse($utc, 'UTC'), 'status' => 'NS', ...$attributes]);
}

/**
 * @return array<string, mixed>
 */
function periodData(string $query): array
{
    return test()->getJson("/api/v1/matchday?tz=UTC&{$query}")->assertOk()->json('data');
}

/**
 * @return array<int, string>
 */
function periodSlugs(array $data): array
{
    return collect($data['days'] ?? $data['groups'])->flatMap(fn (array $entry): array => $entry['matches'])->pluck('slug')->all();
}

it('lists the whole month by date, results included', function () {
    periodMatch('last-of-october', '2026-10-31 23:00:00', ['status' => 'Finished']);
    periodMatch('first-day', '2026-11-01 00:00:00', ['status' => 'Finished']);
    periodMatch('late-on-the-twentieth', '2026-11-20 18:00:00');
    periodMatch('early-on-the-twentieth', '2026-11-20 03:00:00');
    periodMatch('last-day', '2026-11-30 23:00:00');
    periodMatch('december', '2026-12-01 00:00:00');

    $data = periodData('view=month');

    expect(collect($data['days'])->pluck('date')->all())->toBe(['2026-11-01', '2026-11-20', '2026-11-30'])
        ->and(periodSlugs($data))->toBe(['first-day', 'early-on-the-twentieth', 'late-on-the-twentieth', 'last-day'])
        ->and($data['total'])->toBe(4)
        ->and($data['groups'])->toBe([]);
});

it('draws the month and the days by the calendar of the visitor', function () {
    periodMatch('late-october-in-utc', '2026-10-31 20:00:00');
    periodMatch('late-november-in-utc', '2026-11-30 20:00:00');

    $data = $this->getJson('/api/v1/matchday?view=month&tz=Asia/Dhaka')->assertOk()->json('data');

    expect($data['days'])->toHaveCount(1)
        ->and($data['days'][0]['date'])->toBe('2026-11-01')
        ->and(periodSlugs($data))->toBe(['late-october-in-utc']);
});

it('lists the whole calendar year', function () {
    periodMatch('last-year', '2025-12-31 12:00:00');
    periodMatch('january', '2026-01-01 12:00:00', ['status' => 'Finished']);
    periodMatch('december', '2026-12-31 12:00:00');
    periodMatch('next-year', '2027-01-01 12:00:00');

    expect(periodSlugs(periodData('view=year')))->toBe(['january', 'december']);
});

it('gives a long period a page at a time', function () {
    foreach (range(1, 61) as $minute) {
        periodMatch("match-{$minute}", '2026-11-02 10:'.sprintf('%02d', $minute % 60).':00');
    }

    expect(periodSlugs(periodData('view=month')))->toHaveCount(60)
        ->and(periodData('view=month')['total'])->toBe(61)
        ->and(periodSlugs(periodData('view=month&limit=120')))->toHaveCount(61)
        ->and(periodSlugs(periodData('view=month&limit=2')))->toHaveCount(2);
});

it('shows only the matches of the chosen team', function (string $view) {
    $india = periodTeam('India');
    periodMatch('india-at-home', '2026-11-15 14:00:00', ['local_team_id' => $india->id]);
    periodMatch('india-away', '2026-11-15 16:00:00', ['visitor_team_id' => $india->id]);
    periodMatch('others', '2026-11-15 15:00:00');

    expect(periodSlugs(periodData("view={$view}&team=india")))->toEqualCanonicalizing(['india-at-home', 'india-away'])
        ->and(periodSlugs(periodData("view={$view}&team=atlantis")))->toBe([]);
})->with(['today', 'week', 'month', 'year']);

it('offers the teams and competitions that go with the other choice', function () {
    $cup = League::factory()->create(['name' => 'Cup', 'slug' => 'cup']);
    $shield = League::factory()->create(['name' => 'Shield', 'slug' => 'shield']);
    [$india, $england, $kenya, $nepal, $undecided] = array_map(periodTeam(...), ['India', 'England', 'Kenya', 'Nepal', 'TBC']);
    periodMatch('cup-match', '2026-11-16 10:00:00', ['league_id' => $cup->id, 'local_team_id' => $india->id, 'visitor_team_id' => $england->id]);
    periodMatch('shield-match', '2026-11-17 10:00:00', ['league_id' => $shield->id, 'local_team_id' => $kenya->id, 'visitor_team_id' => $undecided->id]);
    periodMatch('next-month', '2026-12-17 10:00:00', ['local_team_id' => $nepal->id, 'visitor_team_id' => $nepal->id]);

    $all = periodData('view=month');
    $byCup = periodData('view=month&competition=cup');
    $byKenya = periodData('view=month&team=kenya');

    expect(array_column($all['teams'], 'name'))->toBe(['England', 'India', 'Kenya'])
        ->and(array_column($all['competitions'], 'slug'))->toBe(['cup', 'shield'])
        ->and(array_column($byCup['teams'], 'name'))->toBe(['England', 'India'])
        ->and(array_column($byCup['competitions'], 'slug'))->toBe(['cup', 'shield'])
        ->and(array_column($byKenya['competitions'], 'slug'))->toBe(['shield'])
        ->and(periodSlugs(periodData('view=month&team=kenya&competition=cup')))->toBe([]);
});

it('keeps the chosen team and competition in the lists when they have no match', function () {
    League::factory()->create(['name' => 'Cup', 'slug' => 'cup']);
    periodTeam('Nepal');

    $data = periodData('view=today&team=nepal&competition=cup');

    expect(array_column($data['teams'], 'slug'))->toBe(['nepal'])
        ->and(array_column($data['competitions'], 'slug'))->toBe(['cup']);
});
