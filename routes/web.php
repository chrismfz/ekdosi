<?php

use App\Http\Controllers\CompanyBackupDownloadController;
use App\Http\Controllers\ExpenseDocumentDownloadController;
use App\Http\Controllers\Portal\HomeController as PortalHomeController;
use App\Http\Controllers\Portal\LoginController as PortalLoginController;
use App\Http\Controllers\PublicInvoicePdfController;
use App\Http\Middleware\EnsurePortalAuthenticated;
use Illuminate\Support\Facades\Route;

// The app is the Filament admin panel; there is no public landing page.
// Send the root straight to the panel (which then routes to login / tenant).
Route::redirect('/', '/admin');

/**
 * Customer portal (Slice 0) — a customer-facing login on the dedicated `portal`
 * guard, wholly separate from the operator/Filament panel (/admin). This slice
 * is the auth SHELL only: login/logout + an authenticated placeholder. No
 * customer data is exposed here yet. The login (throttled) is at /login; `/`
 * still goes to /admin for operators.
 */
Route::get('/login', [PortalLoginController::class, 'show'])->name('portal.login');
Route::post('/login', [PortalLoginController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('portal.login.attempt');
Route::post('/logout', [PortalLoginController::class, 'logout'])->name('portal.logout');
Route::get('/portal', [PortalHomeController::class, 'index'])
    ->middleware(EnsurePortalAuthenticated::class)
    ->name('portal.home');

// Legacy redirects — the three myDATA consoles moved under the «Κονσόλα myDATA»
// cluster (admin/{tenant}/mydata/{sales,expenses,e3}). Keep old bookmarks alive.
// Plain {tenant} (not the {tenant:slug} model binding) — we only pass the slug
// string straight through to the new route.
foreach ([
    'my-data-console' => 'filament.admin.mydata.pages.sales',
    'my-data-console-expenses' => 'filament.admin.mydata.pages.expenses',
    'my-data-e3-overview' => 'filament.admin.mydata.pages.e3',
] as $oldSlug => $newRoute) {
    Route::get("admin/{tenant}/{$oldSlug}", fn (string $tenant) => redirect()->route($newRoute, ['tenant' => $tenant]))
        ->middleware('web');
}

// Public, SIGNED official-invoice PDF (the "παραστατικό ΑΑΔΕ" link the WHMCS
// bridge surfaces). Auth-less but the `signed` middleware makes the URL
// unforgeable; the controller refuses drafts and exposes only the PDF.
Route::get('/invoice/{invoice}/official-pdf', PublicInvoicePdfController::class)
    ->middleware('signed')
    ->name('public.invoice.pdf');

// Operator backup download — AUTH + SIGNED (short-lived, generated only for
// users who may view companies). Streams the bundle from disk so a large `full`
// backup isn't buffered in memory the way a Livewire-returned download would be.
Route::get('/company-backups/{run}/download', CompanyBackupDownloadController::class)
    ->middleware(['auth', 'signed'])
    ->name('company-backups.download');

// Expense attachment — AUTH + SIGNED + tenant-checked (see controller). Streams
// the private supplier-document scan from the local disk.
Route::get('/expenses/{expense}/document', ExpenseDocumentDownloadController::class)
    ->middleware(['auth', 'signed'])
    ->name('expenses.document.download');

// Best-effort FPM opcache reset after an in-app update — resetting from the CLI
// updater process can't touch the FPM pool, so `ekdosi:self-update` self-hits
// this route (SIGNED, short-lived URL) to clear the shared opcache with no root.
// Auth-less by design (the CLI worker has no session); the `signed` middleware
// (HMAC over APP_KEY) makes the URL unforgeable and it only resets opcache.
Route::get('/internal/opcache-flush', function () {
    $reset = function_exists('opcache_reset') ? (bool) opcache_reset() : false;

    return response()->json(['opcache_reset' => $reset]);
})->middleware('signed')->name('internal.opcache-flush');
