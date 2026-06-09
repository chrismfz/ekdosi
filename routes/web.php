<?php

use App\Http\Controllers\CompanyBackupDownloadController;
use App\Http\Controllers\PublicInvoicePdfController;
use Illuminate\Support\Facades\Route;

// The app is the Filament admin panel; there is no public landing page.
// Send the root straight to the panel (which then routes to login / tenant).
Route::redirect('/', '/admin');

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
