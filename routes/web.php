<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This application is a headless API consumed by the Next.js frontend, so the
| only web route is a small JSON status document. Everything else lives in
| routes/api/v1.php under the /api/v1 prefix.
|
*/

Route::get('/', fn() => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
    'api' => url('/api/v1'),
]))->name('home');
