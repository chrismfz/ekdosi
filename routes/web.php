<?php

use App\Http\Controllers\CompanyBackupDownloadController;
use App\Http\Controllers\ExpenseDocumentDownloadController;
use App\Http\Controllers\Portal\DocumentPdfController as PortalDocumentPdfController;
use App\Http\Controllers\Portal\HomeController as PortalHomeController;
use App\Http\Controllers\Portal\LoginController as PortalLoginController;
use App\Http\Controllers\Portal\PasswordResetController as PortalPasswordResetController;
use App\Http\Controllers\Portal\ProfileController as PortalProfileController;
use App\Http\Controllers\PublicInvoicePdfController;
use App\Http\Middleware\EnsurePortalAuthenticated;
use Illuminate\Support\Facades\Route;

// Two wholly separate surfaces: the operator/Filament panel at /admin and the
// customer portal at /user. The root `/` is an INTENTIONAL blank placeholder —
// it reveals neither surface (operators bookmark /admin, customers /user), so a
// stranger landing on `/` learns nothing about the app's structure. (When the
// app isn't installed yet, the EnsureInstalled middleware still redirects `/`
// to /install before this view is reached.)
Route::view('/', 'root-placeholder');

/**
 * Customer portal — a customer-facing surface on the dedicated `portal` guard,
 * wholly separate from the operator/Filament panel (/admin). Everything lives
 * under /user (login/logout/home/settings/document), so /admin ⟂ /user is a
 * clean split. Route NAMES stay `portal.*` (the internal guard/namespace name);
 * only the URL prefix is /user. Unauthenticated hits on a protected /user route
 * are redirected to /user/login by EnsurePortalAuthenticated.
 */
Route::get('/user/login', [PortalLoginController::class, 'show'])->name('portal.login');
Route::post('/user/login', [PortalLoginController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('portal.login.attempt');
Route::post('/user/logout', [PortalLoginController::class, 'logout'])->name('portal.logout');

// Password reset / invited-login claim (guest — for logged-out customers). The
// request endpoint is generic + honeypotted + throttled per-IP here and per-email
// in the controller. See PasswordResetController.
Route::get('/user/forgot-password', [PortalPasswordResetController::class, 'showLinkRequest'])
    ->name('portal.password.request');
Route::post('/user/forgot-password', [PortalPasswordResetController::class, 'sendLink'])
    ->middleware('throttle:20,60')->name('portal.password.email');
Route::get('/user/reset-password/{token}', [PortalPasswordResetController::class, 'showReset'])
    ->name('portal.password.reset');
Route::post('/user/reset-password', [PortalPasswordResetController::class, 'reset'])
    ->middleware('throttle:20,60')->name('portal.password.update');
Route::middleware(EnsurePortalAuthenticated::class)->group(function (): void {
    Route::get('/user', [PortalHomeController::class, 'index'])->name('portal.home');
    // Official PDF of one of the customer's own documents (grant-scoped, streamed).
    // Throttled: each hit is a heavy DomPDF render (raises memory_limit/time_limit),
    // so cap the rate to keep a tight loop (or a hijacked session) from exhausting
    // FPM workers — the heaviest portal endpoint, so a tighter cap than the rest.
    Route::get('/user/document/{invoice}/pdf', PortalDocumentPdfController::class)
        ->where('invoice', '[0-9]+')
        ->middleware('throttle:20,1')
        ->name('portal.document.pdf');
    Route::get('/user/settings', [PortalProfileController::class, 'show'])->name('portal.profile');
    Route::post('/user/settings', [PortalProfileController::class, 'update'])
        ->middleware('throttle:12,1')->name('portal.profile.update');
    // Throttled: current_password is verified here, so cap guessing (a hijacked
    // session brute-forcing the current password to take over the account).
    Route::post('/user/settings/password', [PortalProfileController::class, 'updatePassword'])
        ->middleware('throttle:8,1')->name('portal.profile.password');
});

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
