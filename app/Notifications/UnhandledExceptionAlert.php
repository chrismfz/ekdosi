<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * OPS-3 ops alert: an unhandled exception was reported in production. Carries
 * only STRINGS (never the Throwable — it isn't safely serialisable for the
 * queue). Queued so a mail outage can't slow the failing request/command; the
 * exception is still written to laravel.log by the normal handler regardless.
 */
class UnhandledExceptionAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $exceptionClass,
        public string $message,
        public string $location,   // relative file:line
        public string $context,    // "HTTP POST invoices/…" or "CLI: mydata:reconcile-sales"
        public string $occurredAt,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) config('app.name', 'ekdosi');

        return (new MailMessage)
            ->subject('⛔ Σφάλμα εφαρμογής ('.$app.') — '.class_basename($this->exceptionClass))
            ->greeting('Ανεπίλυτο σφάλμα στην εφαρμογή')
            ->line('Τύπος: **'.$this->exceptionClass.'**')
            ->line('Μήνυμα: '.($this->message !== '' ? $this->message : '(κενό)'))
            ->line('Σημείο: '.$this->location)
            ->line('Πλαίσιο: '.$this->context)
            ->line('Πότε: '.$this->occurredAt)
            ->line('Το πλήρες stack trace είναι στο storage/logs/laravel.log. '
                .'Παρόμοια σφάλματα ομαδοποιούνται — δεν θα λάβεις ξανά ειδοποίηση για το ίδιο '
                .'σφάλμα μέσα στο παράθυρο περιορισμού.');
    }
}
