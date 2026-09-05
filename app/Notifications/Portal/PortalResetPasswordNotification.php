<?php

namespace App\Notifications\Portal;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The customer-portal password-reset email — SEPARATE from the operator reset
 * notification so the link targets the portal route (`/user/reset-password/…`),
 * never the operator one. Queued so the mail-send time is off the request path
 * (flatter timing → less of an enumeration signal). Doubles as the invited-login
 * «claim» mail: an operator-invited login sets its first password through the
 * same link.
 */
class PortalResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    public function toMail($notifiable): MailMessage
    {
        $url = route('portal.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = config('auth.passwords.customer_users.expire', 60);

        return (new MailMessage)
            ->subject('Ορισμός/επαναφορά κωδικού — Πύλη πελατών')
            ->greeting('Γεια σου,')
            ->line('Ζητήθηκε ορισμός ή επαναφορά κωδικού για τον λογαριασμό σου στην πύλη πελατών.')
            ->action('Όρισε κωδικό', $url)
            ->line("Ο σύνδεσμος λήγει σε {$minutes} λεπτά.")
            ->line('Αν δεν το ζήτησες εσύ, αγνόησε αυτό το email — δεν έγινε καμία αλλαγή στον λογαριασμό σου.');
    }
}
