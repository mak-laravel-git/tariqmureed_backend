<?php

use App\Http\Controllers\Api\V1\Admin\SportMonksIntegrationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CompetitionController;
use App\Http\Controllers\Api\V1\MatchController;
use App\Http\Controllers\Api\V1\MatchdayController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\SeriesController;
use App\Http\Controllers\Api\V1\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| Controllers live in app/Http/Controllers/Api/V1/{Domain}.
| Form requests: app/Http/Requests/Api/V1/{Domain}.
| API resources: app/Http/Resources/Api/V1.
|
| Authentication uses Sanctum personal access tokens:
| send "Authorization: Bearer <token>" on protected routes.
|
*/

// ── Authentication ───────────────────────────────────────────────────────────
Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function () {
    Route::post('login', 'login')->middleware('throttle:auth')->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', 'me')->name('me');
        Route::post('logout', 'logout')->name('logout');
    });
});

// ── Cricket data (public, read-only, served from the local database) ─────────
// The data is copied from SportMonks by `php artisan sportmonks:sync`, never fetched per request.
Route::get('competitions', [CompetitionController::class, 'index'])->name('competitions.index');
Route::get('competitions/spotlight', [CompetitionController::class, 'spotlight'])->name('competitions.spotlight');

Route::get('matches', [MatchController::class, 'index'])->name('matches.index');
Route::get('matches/summary', [MatchController::class, 'summary'])->name('matches.summary');
Route::get('matches/{slug}', [MatchController::class, 'show'])->name('matches.show');
Route::get('matchday', MatchdayController::class)->name('matchday');

Route::get('series', [SeriesController::class, 'index'])->name('series.index');
Route::get('series/{slug}', [SeriesController::class, 'show'])->name('series.show');

Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
Route::get('teams/directory', [TeamController::class, 'directory'])->name('teams.directory');
Route::get('teams/{slug}', [TeamController::class, 'show'])->name('teams.show');
Route::get('teams/{slug}/fixtures', [TeamController::class, 'fixtures'])->name('teams.fixtures');

Route::get('players/{slug}', [PlayerController::class, 'show'])->name('players.show');

// ── Admin: SportMonks integration ────────────────────────────────────────────
Route::prefix('integrations/sportmonks')->name('integrations.sportmonks.')
    ->middleware('auth:sanctum')
    ->controller(SportMonksIntegrationController::class)
    ->group(function () {
        Route::get('/', 'show')->middleware('can:integrations.view')->name('show');
        Route::post('sync', 'sync')->middleware('can:integrations.sync')->name('sync');
    });
