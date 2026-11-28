<?php

use App\Models\Fixture;
use App\Models\Team;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    // 02:00 on 28 November in Dhaka, 20:00 on 27 November in London.
    $this->travelTo(Date::parse('2026-11-27 20:00:00', 'UTC'));
});

function fixtureStarting(string $utc, string $slug): Fixture
{
    return Fixture::factory()->create(['slug' => $slug, 'starting_at' => Date::parse($utc, 'UTC'), 'status' => 'NS']);
}

it('groups the matchday by the calendar of the visitor', function (array $query, string $date, array $slugs) {
    fixtureStarting('2026-11-27 12:00:00', 'london-today');
    fixtureStarting('2026-11-28 03:00:00', 'dhaka-today');

    $data = $this->getJson('/api/v1/matchday?'.http_build_query(['view' => 'today', ...$query]))->assertOk()->json('data');

    expect($data['date'])->toBe($date)
        ->and(collect($data['groups'])->flatMap(fn (array $group): array => $group['matches'])->pluck('slug')->all())->toBe($slugs);
})->with([
    'no time zone' => [[], '2026-11-28', ['dhaka-today']],
    'London' => [['tz' => 'Europe/London'], '2026-11-27', ['london-today']],
    'an old browser name' => [['tz' => 'Asia/Calcutta'], '2026-11-28', ['dhaka-today']],
    'an unknown name' => [['tz' => 'Mars/Olympus'], '2026-11-28', ['dhaka-today']],
]);

it('marks a match as today when it is today for the visitor', function (string $timezone, bool $isToday) {
    fixtureStarting('2026-11-28 03:00:00', 'a');

    $this->getJson('/api/v1/matchday?view=upcoming&tz='.$timezone)->assertOk()->assertJsonPath('data.groups.0.matches.0.isToday', $isToday);
})->with([
    'Dhaka, same day' => ['Asia/Dhaka', true],
    'London, the next day' => ['Europe/London', false],
]);

it('ignores a time zone sent as a list', function () {
    $this->getJson('/api/v1/matchday?view=today&tz[]=Europe/London')->assertOk()->assertJsonPath('data.date', '2026-11-28');
});

it('gives the alert bar what it needs to show the start in any time zone', function () {
    $home = Team::factory()->create(['name' => 'India']);
    $away = Team::factory()->create(['name' => 'Australia']);
    fixtureStarting('2026-11-28 03:00:00', 'a')->update(['local_team_id' => $home->id, 'visitor_team_id' => $away->id]);

    $entry = collect($this->getJson('/api/v1/ticker')->assertOk()->json('data'))->firstWhere('href', '/matches/a');

    expect($entry['lead'])->toBe('NEXT · India v Australia')
        ->and($entry['startsAt'])->toBe('2026-11-28T03:00:00+00:00')
        ->and($entry['text'])->toBe('NEXT · India v Australia · Sat 09:00 GMT+6');
});
