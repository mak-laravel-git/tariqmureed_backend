<?php

use App\Http\Controllers\Api\V1\AdController;
use App\Http\Controllers\Api\V1\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Api\V1\Admin\AdvertisementController as AdminAdvertisementController;
use App\Http\Controllers\Api\V1\Admin\ArticleController as AdminArticleController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\CompetitionController as AdminCompetitionController;
use App\Http\Controllers\Api\V1\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Api\V1\Admin\PageController as AdminPageController;
use App\Http\Controllers\Api\V1\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Api\V1\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Api\V1\Admin\SeoController as AdminSeoController;
use App\Http\Controllers\Api\V1\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\V1\Admin\SportMonksIntegrationController;
use App\Http\Controllers\Api\V1\Admin\StaffController as AdminStaffController;
use App\Http\Controllers\Api\V1\Admin\WriterController as AdminWriterController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CompetitionController;
use App\Http\Controllers\Api\V1\MatchController;
use App\Http\Controllers\Api\V1\MatchdayController;
use App\Http\Controllers\Api\V1\NewsController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\RankingController;
use App\Http\Controllers\Api\V1\SeoController;
use App\Http\Controllers\Api\V1\SeriesController;
use App\Http\Controllers\Api\V1\SiteSettingsController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TickerController;
use App\Http\Controllers\Api\V1\WriterController;
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
// ── Stories and authors (written in the admin dashboard) ─────────────────────
Route::get('articles/featured', [ArticleController::class, 'featured'])->name('articles.featured');
Route::get('articles/top-headlines', [ArticleController::class, 'topHeadlines'])->name('articles.top-headlines');
Route::get('articles/most-read', [ArticleController::class, 'mostRead'])->name('articles.most-read');
Route::get('articles', [ArticleController::class, 'index'])->name('articles.index');
Route::get('articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');
Route::get('news', NewsController::class)->name('news');
Route::get('rankings', RankingController::class)->name('rankings');
Route::get('writers', [WriterController::class, 'index'])->name('writers.index');
Route::get('writers/{slug}', [WriterController::class, 'show'])->name('writers.show');
Route::get('writers/{slug}/articles', [WriterController::class, 'articles'])->name('writers.articles');

Route::get('ads', AdController::class)->name('ads');
Route::get('ticker', TickerController::class)->name('ticker');

Route::get('settings', SiteSettingsController::class)->name('settings.show');
Route::get('seo', [SeoController::class, 'show'])->name('seo.show');
Route::get('sitemap', [SeoController::class, 'sitemap'])->name('sitemap');
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

    Route::get('articles/tags', [AdminArticleController::class, 'tags'])->middleware('can:articles.index')->name('articles.tags');
    Route::get('advertisements/placements', [AdminAdvertisementController::class, 'placements'])->middleware('can:advertisements.index')->name('advertisements.placements');

    // Modules that share the common admin table and form: path => [controller, permission prefix].
    foreach ([
        'articles' => [AdminArticleController::class, 'articles'],
        'categories' => [AdminCategoryController::class, 'categories'],
        'writers' => [AdminWriterController::class, 'writers'],
        'advertisements' => [AdminAdvertisementController::class, 'advertisements'],
    ] as $path => [$controller, $permission]) {
        Route::prefix($path)->name("{$path}.")->controller($controller)->group(function () use ($permission) {
            Route::get('/', 'index')->middleware("can:{$permission}.index")->name('index');
            Route::post('/', 'store')->middleware("can:{$permission}.create")->name('store');
            Route::get('{id}', 'show')->middleware("can:{$permission}.view")->name('show');
            Route::put('{id}', 'update')->middleware("can:{$permission}.edit")->name('update');
            Route::delete('{id}', 'destroy')->middleware("can:{$permission}.delete")->name('destroy');
        });
    }

    Route::get('media', [AdminMediaController::class, 'index'])->middleware('can:media.index')->name('media.index');
    Route::post('media', [AdminMediaController::class, 'store'])->middleware('can:media.create')->name('media.store');
    Route::delete('media/{id}', [AdminMediaController::class, 'destroy'])->middleware('can:media.delete')->name('media.destroy');
    Route::get('media/source', [AdminMediaController::class, 'source'])->middleware('can:media.index')->name('media.source');
    Route::post('media/{id}/crop', [AdminMediaController::class, 'crop'])->middleware('can:media.create')->name('media.crop');

    Route::get('activity-logs', [AdminActivityLogController::class, 'index'])->middleware('can:activity.index')->name('activity-logs.index');
    Route::get('activity-logs/{id}', [AdminActivityLogController::class, 'show'])->middleware('can:activity.index')->name('activity-logs.show');

    Route::get('competitions', [AdminCompetitionController::class, 'index'])->middleware('can:integrations.view')->name('competitions.index');
    Route::get('competitions/arrangement', [AdminCompetitionController::class, 'arrangement'])->middleware('can:settings.view')->name('competitions.arrangement');
    Route::put('competitions/arrangement', [AdminCompetitionController::class, 'arrange'])->middleware('can:settings.edit')->name('competitions.arrange');
    Route::get('competitions/{id}', [AdminCompetitionController::class, 'show'])->middleware('can:integrations.view')->name('competitions.show');

    Route::prefix('integrations/sportmonks')->name('integrations.sportmonks.')
        ->controller(SportMonksIntegrationController::class)
        ->group(function () {
            Route::get('/', 'show')->middleware('can:integrations.view')->name('show');
            Route::post('sync', 'sync')->middleware('can:integrations.sync')->name('sync');
        });

    Route::get('settings', [AdminSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.show');
    Route::put('settings', [AdminSettingsController::class, 'update'])->middleware('can:settings.edit')->name('settings.update');

    Route::get('seo', [AdminSeoController::class, 'show'])->middleware('can:settings.view')->name('seo.show');
    Route::put('seo', [AdminSeoController::class, 'update'])->middleware('can:settings.edit')->name('seo.update');

    Route::prefix('pages')->name('pages.')->controller(AdminPageController::class)->group(function () {
        Route::get('/', 'index')->middleware('can:pages.view')->name('index');
        Route::get('{slug}', 'show')->middleware('can:pages.view')->name('show');
        Route::put('{slug}', 'update')->middleware('can:pages.edit')->name('update');
        Route::delete('{slug}', 'destroy')->middleware('can:pages.edit')->name('destroy');
    });
});
