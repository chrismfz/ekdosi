<?php

use App\Http\Controllers\CompanyBackupDownloadController;
use App\Http\Controllers\PublicInvoicePdfController;
use Illuminate\Support\Facades\Route;

// The app is the Filament admin panel; there is no public landing page.
// Send the root straight to the panel (which then routes to login / tenant).
Route::redirect('/', '/admin');

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
