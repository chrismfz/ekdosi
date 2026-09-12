<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Admin-side «reset/disable 2FA» for another user — reused on the Users table
 * (row action) AND the Edit-user page header, so both stay identical.
 *
 * Why admins can only RESET, never ENABLE, someone else's 2FA: TOTP enrolment
 * requires the target user to scan the QR with THEIR OWN authenticator app, so
 * turning it ON is inherently self-service (the profile page). This action is
 * the recovery path — a user who loses their device AND recovery codes would
 * otherwise be locked out permanently (only a manual DB UPDATE could fix it).
 * After a reset the user simply re-enrols from their own profile.
 */
class ResetTwoFactorAction
{
    public static function make(string $name = 'reset_2fa'): Action
    {
        return Action::make($name)
            ->label('Επαναφορά 2FA')
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            // Only meaningful when the user actually HAS a TOTP secret enrolled.
            ->visible(fn (?User $record): bool => filled($record?->app_authentication_secret))
            ->requiresConfirmation()
            ->modalHeading('Επαναφορά 2FA')
            ->modalDescription(fn (User $record): string => "Θα αφαιρεθεί το 2FA του {$record->email} (κωδικός TOTP + recovery codes). "
                .'Ο χρήστης θα μπορεί να το ξανα-ενεργοποιήσει από το προφίλ του.')
            ->modalSubmitActionLabel('Επαναφορά')
            ->action(function (User $record): void {
                // forceFill: these columns are guarded (out of $fillable) and
                // carry the MaybeEncrypted cast — nulling them clears both.
                $record->forceFill([
                    'app_authentication_secret' => null,
                    'app_authentication_recovery_codes' => null,
                ])->save();

                Notification::make()
                    ->title("Έγινε επαναφορά 2FA για {$record->email}")
                    ->success()
                    ->send();
            });
    }
}
