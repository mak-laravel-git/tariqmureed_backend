<?php

use App\Http\Controllers\Api\V1\Admin\CompetitionController as AdminCompetitionController;
use App\Http\Controllers\Api\V1\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\PageController as AdminPageController;
use App\Http\Controllers\Api\V1\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Api\V1\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Api\V1\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\V1\Admin\SportMonksIntegrationController;
use App\Http\Controllers\Api\V1\Admin\StaffController as AdminStaffController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CompetitionController;
use App\Http\Controllers\Api\V1\MatchController;
use App\Http\Controllers\Api\V1\MatchdayController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\SeriesController;
use App\Http\Controllers\Api\V1\SiteSettingsController;
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

// ── Public site content (edited in the admin dashboard) ──────────────────────
Route::get('settings', SiteSettingsController::class)->name('settings.show');
Route::get('pages/{slug}', [PageController::class, 'show'])->name('pages.show');

// ── Admin dashboard API (the Next.js admin calls /api/v1/admin/...) ──────────
Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function () {
    Route::get('dashboard', AdminDashboardController::class)->middleware('can:dashboard.view')->name('dashboard');

    // The signed-in administrator's own account: no permission needed.
    Route::put('profile', [AdminProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [AdminProfileController::class, 'password'])->middleware('throttle:auth')->name('profile.password');

    Route::prefix('staff')->name('staff.')->controller(AdminStaffController::class)->group(function () {
        Route::get('/', 'index')->middleware('can:users.index')->name('index');
        Route::get('{id}', 'show')->middleware('can:users.view')->name('show');
        Route::put('{id}', 'update')->middleware('can:users.edit')->name('update');
        Route::delete('{id}', 'destroy')->middleware('can:users.delete')->name('destroy');
    });

    Route::get('roles/permissions', [AdminRoleController::class, 'permissions'])->middleware('can:roles.view')->name('roles.permissions');
    Route::put('roles/{role}/permissions', [AdminRoleController::class, 'update'])->middleware('can:roles.edit')->name('roles.update');

    Route::get('competitions', [AdminCompetitionController::class, 'index'])->middleware('can:integrations.view')->name('competitions.index');
    Route::get('competitions/{id}', [AdminCompetitionController::class, 'show'])->middleware('can:integrations.view')->name('competitions.show');

    Route::prefix('integrations/sportmonks')->name('integrations.sportmonks.')
        ->controller(SportMonksIntegrationController::class)
        ->group(function () {
            Route::get('/', 'show')->middleware('can:integrations.view')->name('show');
            Route::post('sync', 'sync')->middleware('can:integrations.sync')->name('sync');
        });

    Route::get('settings', [AdminSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.show');
    Route::put('settings', [AdminSettingsController::class, 'update'])->middleware('can:settings.edit')->name('settings.update');

    Route::prefix('pages')->name('pages.')->controller(AdminPageController::class)->group(function () {
        Route::get('/', 'index')->middleware('can:pages.view')->name('index');
        Route::get('{slug}', 'show')->middleware('can:pages.view')->name('show');
        Route::put('{slug}', 'update')->middleware('can:pages.edit')->name('update');
        Route::delete('{slug}', 'destroy')->middleware('can:pages.edit')->name('destroy');
    });
});
