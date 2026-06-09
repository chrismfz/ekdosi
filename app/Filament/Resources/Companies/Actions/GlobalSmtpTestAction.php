<?php

namespace App\Filament\Resources\Companies\Actions;

use App\Services\TenantRoleProvisioner;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

/**
 * Super-admin-only probe of the GLOBAL mailer (the app-wide MAIL_MAILER from
 * .env) — the counterpart to the per-company «Send a test email» on the Company
 * form. A tenant without its own SMTP falls back to this global mailer, so being
 * able to verify it (without going through any tenant) is what tells the operator
 * whether `.env` mail works at all — e.g. catching the common MAIL_MAILER=log
 * («δεν φεύγει τίποτα») misconfiguration.
 *
 * Sits in the Companies list header (the Company resource is already panel-global
 * / super_admin), with an explicit super_admin re-check for defense in depth.
 */
class GlobalSmtpTestAction
{
    public static function make(): Action
    {
        return Action::make('test_global_smtp')
            ->label('Δοκιμή global SMTP (.env)')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            // Filament enforces ->authorize() on mount (mountAction checks it),
            // unlike ->visible() which only governs rendering — so authorize() is
            // the real security boundary; visible() just hides the button. The
            // in-action recheck below stays as defense-in-depth.
            ->visible(fn () => self::isSuperAdmin())
            ->authorize(fn () => self::isSuperAdmin())
            ->modalHeading('Δοκιμή global mailer (.env)')
            ->modalDescription(fn () => new HtmlString(
                'Στέλνει ένα δοκιμαστικό email μέσω του <strong>global</strong> mailer του .env '
                .'(όχι κάποιας εταιρίας). Τρέχων mailer: <code>'.e((string) config('mail.default')).'</code>'
                .', αποστολέας: <code>'.e((string) config('mail.from.address')).'</code>.'
                .(config('mail.default') === 'log'
                    ? '<br><strong>⚠ MAIL_MAILER=log</strong> — δεν φεύγει πραγματικό email· γράφεται μόνο στο log.'
                    : '')
            ))
            ->modalSubmitActionLabel('Αποστολή')
            ->schema([
                TextInput::make('to')
                    ->label('Αποστολή σε')
                    ->email()->required()
                    ->helperText('Διεύθυνση παραλήπτη για τη δοκιμή.'),
            ])
            ->action(function (array $data): void {
                if (! self::isSuperAdmin()) {
                    Notification::make()->title('Μόνο super admin.')->danger()->send();

                    return;
                }

                $to = (string) $data['to'];
                $mailer = (string) config('mail.default');

                try {
                    $html = '<p>Δοκιμαστικό email από το ekdosi μέσω του global mailer (.env).</p>'
                        .'<p>Αν το έλαβες, ο global mailer «<strong>'.e($mailer).'</strong>» δουλεύει.</p>';

                    Mail::html($html, function ($message) use ($to) {
                        $message->to($to)->subject('[ekdosi test] global mailer (.env)');
                    });

                    Notification::make()
                        ->title('Το δοκιμαστικό στάλθηκε')
                        ->body('Mailer: '.$mailer.' → '.$to
                            .($mailer === 'log' ? ' (MAIL_MAILER=log — δες το log, δεν έφυγε email).' : '. Έλεγξε το inbox.'))
                        ->success()->send();
                } catch (\Throwable $e) {
                    // The action is super-admin-only, so surfacing the full SMTP
                    // error (auth/connection reason) is safe and is exactly what
                    // the operator needs to fix .env.
                    Notification::make()
                        ->title('Αποτυχία δοκιμαστικού')
                        ->body($e->getMessage())
                        ->danger()->persistent()->send();
                }
            });
    }

    private static function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantRoleProvisioner::class)->isSuperAdminAnywhere($user);
    }
}
