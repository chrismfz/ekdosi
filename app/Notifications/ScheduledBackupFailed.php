<?php

namespace App\Notifications;

use App\Models\CompanyBackupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ops alert: a SCHEDULED per-company backup ended failed/partial. Sent by
 * RunScheduledCompanyBackups (the unattended path — manual/download runs already
 * surface status in the panel). Queued so a mail outage can't break the
 * scheduler; the failure is also Log::error'd regardless of mail.
 */
class ScheduledBackupFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CompanyBackupRun $run) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->run;
        $company = $run->company?->name ?? ('#'.$run->company_id);
        $partial = $run->status === 'partial';

        $mail = (new MailMessage)
            ->subject(($partial ? '⚠ Μερική αποτυχία' : '⛔ Αποτυχία').' αυτόματου αντιγράφου — '.$company)
            ->greeting('Πρόβλημα στο αυτόματο αντίγραφο')
            ->line('Εταιρία: **'.$company.'**')
            ->line('Κατάσταση: **'.$run->status.'**'.($partial
                ? ' (το τοπικό αντίγραφο γράφτηκε, αλλά κάποιοι απομακρυσμένοι προορισμοί απέτυχαν).'
                : ' (δεν δημιουργήθηκε αντίγραφο).'))
            ->line('Πότε: '.optional($run->started_at)->format('d/m/Y H:i'));

        foreach ((array) $run->destinations as $d) {
            if (($d['status'] ?? null) === 'failed') {
                $mail->line('• '.($d['driver'] ?? '?').': '.($d['message'] ?? 'άγνωστο σφάλμα'));
            }
        }

        return $mail->line('Έλεγξε τις ρυθμίσεις προορισμών στα «Αυτόματα αντίγραφα» της εταιρίας.');
    }
}
