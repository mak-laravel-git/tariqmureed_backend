<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SportMonks Cricket API
    |--------------------------------------------------------------------------
    |
    | Set SPORTMONKS_API_TOKEN in .env and the rest works with these defaults.
    | The token is read on the server only and never sent to the browser.
    |
    */

    'token' => env('SPORTMONKS_API_TOKEN'),

    'base_url' => env('SPORTMONKS_BASE_URL', 'https://cricket.sportmonks.com/api/v2.0'),

    'timeout' => (int) env('SPORTMONKS_TIMEOUT', 15),

    /*
    | Restrict syncing to some of the leagues in your plan (comma separated SportMonks
    | league ids). Empty means every league the plan includes.
    */
    'league_ids' => array_values(array_filter(
        array_map('intval', explode(',', (string) env('SPORTMONKS_LEAGUE_IDS', ''))),
    )),

    /*
    | Plans allow a number of calls per endpoint per hour (the default plan: 2000).
    | The client stops itself `reserve` calls before that limit so a manual sync from
    | the admin panel always has headroom.
    */
    'rate_limit' => [
        'per_endpoint_hour' => (int) env('SPORTMONKS_RATE_LIMIT', 2000),
        'reserve' => (int) env('SPORTMONKS_RATE_RESERVE', 100),
    ],

    'sync' => [
        // Fixture window: days back (results) and days ahead (schedule).
        'days_back' => (int) env('SPORTMONKS_DAYS_BACK', 3),
        'days_ahead' => (int) env('SPORTMONKS_DAYS_AHEAD', 45),
        // Scorecards fetched per run for matches that finished since the last sync.
        'details_per_run' => (int) env('SPORTMONKS_DETAILS_PER_RUN', 15),
        // Squads fetched per run (one call per team and season).
        'squads_per_run' => (int) env('SPORTMONKS_SQUADS_PER_RUN', 40),
    ],

    /*
    | How often each sync runs, as cron expressions (server time, UTC by default).
    |
    | Fast-changing data runs often, slow-changing data twice a day. Calls stay far below the
    | plan limit: `live` makes no call at all while no match is near its start time, `details`
    | only fetches scorecards of matches that just finished, and the rest are a few calls each.
    | Override any of them in .env, e.g. SPORTMONKS_SCHEDULE_FIXTURES="0 * * * *" for hourly.
    */
    'schedule' => [
        'live' => env('SPORTMONKS_SCHEDULE_LIVE', '* * * * *'),                // every minute
        'details' => env('SPORTMONKS_SCHEDULE_DETAILS', '*/5 * * * *'),        // every 5 minutes
        'fixtures' => env('SPORTMONKS_SCHEDULE_FIXTURES', '0 */2 * * *'),      // every 2 hours
        'standings' => env('SPORTMONKS_SCHEDULE_STANDINGS', '10 */3 * * *'),   // every 3 hours
        'leagues' => env('SPORTMONKS_SCHEDULE_LEAGUES', '0 3,15 * * *'),       // every 12 hours
        'venues' => env('SPORTMONKS_SCHEDULE_VENUES', '10 3,15 * * *'),        // every 12 hours
        'teams' => env('SPORTMONKS_SCHEDULE_TEAMS', '20 3,15 * * *'),          // every 12 hours
        'squads' => env('SPORTMONKS_SCHEDULE_SQUADS', '30 4,16 * * *'),        // every 12 hours
    ],

    // Timezone used for "today" and the HH:mm start time labels.
    'display_timezone' => env('SPORTMONKS_DISPLAY_TIMEZONE', 'Asia/Dhaka'),

    // Shown on the live scores page as the data provider.
    'provider_label' => env('SPORTMONKS_PROVIDER_LABEL', 'TMN Sport Live'),

];
