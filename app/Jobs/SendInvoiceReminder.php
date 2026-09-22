<?php

namespace App\Jobs;

use App\Services\Reminders\ReminderSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one payment reminder. A single try: a failure is recorded on the row
 * («Απέτυχε» + the error) and re-sent from the «Υπενθυμίσεις» page — no silent
 * retry that could email the customer twice.
 */
class SendInvoiceReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $reminderId) {}

    public function handle(ReminderSender $sender): void
    {
        $sender->send($this->reminderId);
    }
}
