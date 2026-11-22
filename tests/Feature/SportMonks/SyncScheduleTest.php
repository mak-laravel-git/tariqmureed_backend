<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

/**
 * @return array<string, Event>
 */
function syncEventsByTarget(): array
{
    $events = [];

    foreach (app(Schedule::class)->events() as $event) {
        if (preg_match('/sportmonks:sync (\w+)/', (string) $event->command, $match)) {
            $events[$match[1]] = $event;
        }
    }

    return $events;
}

it('schedules every sync with the intervals from the config', function () {
    $events = syncEventsByTarget();

    expect($events)->toHaveKeys(['all', 'live', 'details', 'fixtures', 'standings', 'leagues', 'venues', 'teams', 'squads']);

    foreach (config('sportmonks.schedule') as $target => $expression) {
        expect($events[$target]->expression)->toBe($expression);
    }
});

it('runs live data every minute and slow-changing data every 12 hours', function () {
    $events = syncEventsByTarget();
    $at = fn (string $time): Carbon => Carbon::parse("2026-11-22 {$time}", 'UTC');

    expect($events['live']->isDue(app()))->toBeTrue();

    foreach (['leagues' => '03:00', 'venues' => '03:10', 'teams' => '03:20', 'squads' => '04:30'] as $target => $morning) {
        $runs = collect(range(0, 23))
            ->filter(function (int $hour) use ($events, $target, $morning, $at): bool {
                [, $minute] = explode(':', $morning);

                Carbon::setTestNow($at(sprintf('%02d:%s', $hour, $minute)));

                return $events[$target]->isDue(app());
            })
            ->values()
            ->all();

        expect($runs)->toHaveCount(2)->and($runs[1] - $runs[0])->toBe(12);
    }

    Carbon::setTestNow();
});

it('does not sync anything while no SportMonks token is configured', function () {
    config(['sportmonks.token' => null]);

    foreach (syncEventsByTarget() as $event) {
        expect($event->filtersPass(app()))->toBeFalse();
    }
});

it('syncs once a token is set', function () {
    config(['sportmonks.token' => 'secret-token-123']);

    expect(syncEventsByTarget()['live']->filtersPass(app()))->toBeTrue();
});
