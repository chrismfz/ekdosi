<?php

use App\Http\Controllers\CompanyBackupDownloadController;
use App\Http\Controllers\ExpenseDocumentDownloadController;
use App\Http\Controllers\Portal\DocumentPdfController as PortalDocumentPdfController;
use App\Http\Controllers\Portal\HomeController as PortalHomeController;
use App\Http\Controllers\Portal\LoginController as PortalLoginController;
use App\Http\Controllers\Portal\PasswordResetController as PortalPasswordResetController;
use App\Http\Controllers\Portal\PaymentController as PortalPaymentController;
use App\Http\Controllers\Portal\ProfileController as PortalProfileController;
use App\Http\Controllers\Portal\StatementController as PortalStatementController;
use App\Http\Controllers\Portal\TicketController as PortalTicketController;
use App\Http\Controllers\PublicInvoicePdfController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\TicketFeedbackController;
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
    // «Η καρτέλα μου» — read-only balance + ledger (same figures as the operator
    // Καρτέλα, grant-scoped). No «pay» yet — that lands with the gateway pillar.
    Route::get('/user/statement', [PortalStatementController::class, 'index'])->name('portal.statement');
    // «Τα αιτήματά μου» (Πυλώνας E, Phase 2) — the customer opens/reads/replies to
    // their support tickets, grant-scoped and fail-closed; only public messages are
    // ever shown. Writes are throttled and go through the OpenTicket/PostTicketMessage
    // choke-point (same state machine as the operator side).
    Route::get('/user/tickets', [PortalTicketController::class, 'index'])->name('portal.tickets');
    Route::get('/user/tickets/create', [PortalTicketController::class, 'create'])->name('portal.tickets.create');
    Route::post('/user/tickets', [PortalTicketController::class, 'store'])
        ->middleware('throttle:12,1')->name('portal.tickets.store');
    Route::get('/user/tickets/{ticket}', [PortalTicketController::class, 'show'])
        ->where('ticket', '[0-9]+')->name('portal.tickets.show');
    Route::post('/user/tickets/{ticket}/reply', [PortalTicketController::class, 'reply'])
        ->where('ticket', '[0-9]+')->middleware('throttle:20,1')->name('portal.tickets.reply');
    Route::post('/user/tickets/{ticket}/rate', [PortalTicketController::class, 'rate'])
        ->where('ticket', '[0-9]+')->middleware('throttle:20,1')->name('portal.tickets.rate');
    // Attachment download — grant-scoped (the ticket is fail-closed resolved), forced
    // download, never public/inline.
    Route::get('/user/tickets/{ticket}/attachments/{attachment}', [PortalTicketController::class, 'attachment'])
        ->where(['ticket' => '[0-9]+', 'attachment' => '[0-9]+'])->name('portal.tickets.attachment');
    // «Πλήρωσε» (B0b) — start a payment against a granted company/customer, then
    // see where to pay. The browser never settles money (manual = operator
    // confirms; online webhook later). Store is throttled.
    Route::get('/user/pay/{customer}', [PortalPaymentController::class, 'create'])
        ->where('customer', '[0-9]+')->name('portal.payment.create');
    Route::post('/user/pay/{customer}', [PortalPaymentController::class, 'store'])
        ->where('customer', '[0-9]+')->middleware('throttle:20,1')->name('portal.payment.store');
    // Hosted-gateway bounce page (flow=redirect): rebuilds + auto-submits the
    // SIGNED provider form to the acquirer (B1, Eurobank vPOS). Grant-scoped.
    Route::get('/user/payment/{intent}/redirect', [PortalPaymentController::class, 'redirect'])
        ->where('intent', '[0-9]+')->name('portal.payment.redirect');
    Route::get('/user/payment/{intent}', [PortalPaymentController::class, 'show'])
        ->where('intent', '[0-9]+')->name('portal.payment.show');
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

// Support feedback — SIGNED, NO login (the emailed link is the authorization). The
// customer rates a closed ticket of a feedback-enabled department. See controller.
Route::get('/support/feedback/{ticket}', [TicketFeedbackController::class, 'show'])
    ->where('ticket', '[0-9]+')->middleware(['signed', 'throttle:30,1'])->name('support.feedback.show');
Route::post('/support/feedback/{ticket}', [TicketFeedbackController::class, 'store'])
    ->where('ticket', '[0-9]+')->middleware(['signed', 'throttle:30,1'])->name('support.feedback.store');

// Operator backup download — AUTH + SIGNED (short-lived, generated only for
// users who may view companies). Streams the bundle from disk so a large `full`
// backup isn't buffered in memory the way a Livewire-returned download would be.
Route::get('/company-backups/{run}/download', CompanyBackupDownloadController::class)
    ->middleware(['auth', 'signed'])
    ->name('company-backups.download');

// Operator ticket-attachment download — AUTH + tenant-checked (see controller).
// Forced download from the private disk, never public/inline.
Route::get('/support/tickets/{ticket}/attachments/{attachment}', TicketAttachmentController::class)
    ->where(['ticket' => '[0-9]+', 'attachment' => '[0-9]+'])
    ->middleware('auth')->name('support.tickets.attachment');

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
