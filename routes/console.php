<?php

use App\Models\League;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| SportMonks sync schedule
|--------------------------------------------------------------------------
|
| Runs only once SPORTMONKS_API_TOKEN is set. The intervals are in config/sportmonks.php
| (`schedule`) and can be changed from .env without touching this file.
|
| Something has to run the Laravel scheduler all the time:
|   - Docker / Coolify: the Dockerfile starts Supervisor (supervisord.conf), nothing else to set up.
|   - VPS without Docker: Supervisor, see supervisor-scheduler.conf in the project root.
|   - cPanel: cron `* * * * * php /path/to/artisan schedule:run`.
|
*/
$sportMonksConfigured = fn (): bool => filled(config('sportmonks.token'));

// First run after the token is set: load everything once, then fall back to the cadence below.
Schedule::command('sportmonks:sync all')
    ->everyFiveMinutes()
    ->when(fn (): bool => $sportMonksConfigured() && League::query()->doesntExist())
    ->withoutOverlapping(30);

/** @var array<string, int> $overlapMinutes Minutes an unfinished run blocks the next one (0 = no lock). */
$overlapMinutes = ['live' => 5, 'details' => 15, 'fixtures' => 30, 'standings' => 30, 'leagues' => 0, 'venues' => 0, 'teams' => 0, 'squads' => 60];

foreach ($overlapMinutes as $target => $minutes) {
    $event = Schedule::command("sportmonks:sync {$target}")
        ->cron((string) config("sportmonks.schedule.{$target}"))
        ->when($sportMonksConfigured);

    if ($minutes > 0) {
        $event->withoutOverlapping($minutes);
    }
}
