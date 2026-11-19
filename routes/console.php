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
| Runs only once SPORTMONKS_API_TOKEN is set. The server needs the usual
| Laravel scheduler entry: `* * * * * php artisan schedule:run`.
| The calls per hour stay far below the plan limit (2000 per endpoint).
|
*/
$sportMonksConfigured = fn (): bool => filled(config('sportmonks.token'));

// First run after the token is set: load everything once, then fall back to the cadence below.
Schedule::command('sportmonks:sync all')
    ->everyFiveMinutes()
    ->when(fn (): bool => $sportMonksConfigured() && League::query()->doesntExist())
    ->withoutOverlapping(30);

Schedule::command('sportmonks:sync live')->everyMinute()->when($sportMonksConfigured)->withoutOverlapping(5);
Schedule::command('sportmonks:sync details')->everyFiveMinutes()->when($sportMonksConfigured)->withoutOverlapping(15);
Schedule::command('sportmonks:sync fixtures')->hourly()->when($sportMonksConfigured)->withoutOverlapping(30);
Schedule::command('sportmonks:sync standings')->everySixHours()->when($sportMonksConfigured)->withoutOverlapping(30);
Schedule::command('sportmonks:sync leagues')->dailyAt('03:00')->when($sportMonksConfigured);
Schedule::command('sportmonks:sync venues')->dailyAt('03:10')->when($sportMonksConfigured);
Schedule::command('sportmonks:sync teams')->dailyAt('03:20')->when($sportMonksConfigured);
Schedule::command('sportmonks:sync squads')->dailyAt('04:00')->when($sportMonksConfigured)->withoutOverlapping(60);
