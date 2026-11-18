<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Versioned API entry point. Each version lives in routes/api/{version}.php
| and is mounted under /api/{version} (Laravel already prefixes /api).
|
*/

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(
    base_path('routes/api/v1.php'),
);
