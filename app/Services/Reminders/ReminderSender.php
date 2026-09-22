<?php

namespace App\Services\Reminders;

use App\Mail\InvoiceReminderMail;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Scopes\CompanyScope;
use App\Services\InvoiceBalance;
use App\Services\InvoicePdfRenderer;
use App\Services\TenantMailerFactory;
use App\Support\CustomerLanguage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends ONE reminder row. The row is first claimed (awaiting/queued/failed →
 * sending in a single conditional UPDATE), so two workers — or a double click
 * on «Αποστολή» — can never email the customer twice. Everything is re-checked
 * at send time: a document paid, cancelled or opted out since it was planned is
 * cancelled with the reason instead of sent.
 */
final class ReminderSender
{
    public function __construct(
        private readonly ReminderPlanner $planner,
        private readonly ReminderMessage $message,
        private readonly InvoiceBalance $balances,
        private readonly InvoicePdfRenderer $pdf,
        private readonly TenantMailerFactory $mailers,
    ) {}

    public function send(int $reminderId): ?InvoiceReminder
    {
        $rows = fn () => InvoiceReminder::query()->withoutGlobalScope(CompanyScope::class)->whereKey($reminderId);

        $claimed = $rows()
            ->whereIn('status', [InvoiceReminder::STATUS_AWAITING, InvoiceReminder::STATUS_QUEUED, InvoiceReminder::STATUS_FAILED])
            ->update(['status' => InvoiceReminder::STATUS_SENDING, 'error_message' => null, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        $row = $rows()->first();
        if ($claimed === 0 || $row === null) {
            return $row;   // already taken, sent, skipped or gone
        }

        $invoice = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $row->company_id)
            ->with(['customer', 'company', 'paymentMethod', 'invoiceType'])
            ->find($row->invoice_id);

        $settings = ReminderSettings::for($invoice?->company ?? $row->company);
        if (($blocker = $this->planner->rowBlocker($row, $invoice, $settings)) !== null) {
            // This claim sent nothing; release the stage only if no EARLIER attempt
            // could have reached the customer.
            return $this->finish($row, InvoiceReminder::STATUS_CANCELLED, reason: $blocker, extra: $row->attempts <= 1 ? ['auto_stage' => null] : []);
        }

        $recipient = (string) $invoice->customer->email;
        $subject = null;

        try {
            $due = ReminderPlanner::dueDateOf($invoice);
            $days = $due !== null ? (int) $due->diffInDays(CarbonImmutable::today(), false) : null;
            $balance = $this->balances->for($invoice)->balance;
            $locale = CustomerLanguage::forDocumentMail($invoice);
            $message = $this->message->build($invoice, $row->stage, $due, $days, $balance, $settings, $locale);
            $subject = $message['subject'];
            $pdf = $settings->attachPdf ? $this->pdf->render($invoice) : null;

            $this->mailers->for($invoice->company)
                ->to($recipient)
                ->send((new InvoiceReminderMail($invoice, $message['subject'], $message['body'], $message['bodyText'], $pdf))->locale($locale));
        } catch (Throwable $e) {
            report($e);

            return $this->finish($row, InvoiceReminder::STATUS_FAILED, recipient: $recipient, subject: $subject, error: mb_substr($e->getMessage(), 0, 2000));
        }

        // Record the send FIRST — it happened; nothing after this may turn it into
        // a «failed» row that invites a second email.
        $this->finish($row, InvoiceReminder::STATUS_SENT, recipient: $recipient, subject: $subject, extra: [
            'sent_at' => now(),
            'due_date' => $due?->toDateString(),
            'days_overdue' => $days,
            'balance' => $balance,
        ]);

        // A reminder is a contact: it feeds «Τελ. επαφή» on the aged-receivables page.
        try {
            $invoice->customer->forceFill(['collection_last_contact_at' => now()->toDateString()])->save();
        } catch (Throwable $e) {
            report($e);
        }

        return $row;
    }

    private function finish(
        InvoiceReminder $row,
        string $status,
        ?string $reason = null,
        ?string $recipient = null,
        ?string $subject = null,
        ?string $error = null,
        array $extra = [],
    ): InvoiceReminder {
        $row->forceFill(array_filter([
            'status' => $status,
            'reason' => $reason,
            'recipient' => $recipient,
            'subject' => $subject !== null ? mb_substr($subject, 0, 500) : null,
            'error_message' => $error,
        ], static fn ($v): bool => $v !== null) + $extra)->save();

        return $row;
    }
}
