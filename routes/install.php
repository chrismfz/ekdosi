<?php

use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web installer routes
|--------------------------------------------------------------------------
|
| Registered WITHOUT the `web` middleware group (see bootstrap/app.php): the
| installer runs before `.env`/APP_KEY/DB exist, so it must not touch session,
| CSRF or cookie encryption. The EnsureInstalled global middleware makes these
| routes reachable ONLY on a pristine host and redirects them to /admin once
| the app is configured; the controller re-checks that on every action, and the
| filesystem token gates every POST.
|
*/

Route::get('/install', [InstallController::class, 'show'])->name('install.show');
Route::post('/install/test-db', [InstallController::class, 'testDb'])->name('install.test-db');
Route::post('/install', [InstallController::class, 'run'])->name('install.run');
