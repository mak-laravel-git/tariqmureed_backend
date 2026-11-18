<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
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
