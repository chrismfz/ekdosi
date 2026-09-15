<?php

namespace App\Services\EInvoice\Concerns;

use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Services\EInvoice\GrProviderSubmitter;
use App\Services\MyDataSubmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The customer «email on acceptance» dispatch, shared by BOTH e-invoice
 * submitters so they cannot drift: the direct {@see MyDataSubmitter}
 * and the provider {@see GrProviderSubmitter}. When a
 * filing reaches VALID, and the tenant opted in
 * (`companies.auto_email_on_mydata_accept`) and the customer hasn't opted out
 * (`customers.auto_email_invoices`, default true), queue the PDF email.
 *
 * Best-effort by design: silent on every opted-out path, and a dispatch hiccup
 * is logged, never thrown — a queue-connection wobble must not mask a successful
 * AADE/provider filing from the operator. The mail can always be re-sent via the
 * ViewInvoice «Resend email» action.
 */
trait DispatchesAcceptanceEmail
{
    protected function dispatchAutoEmailIfEnabled(Invoice $invoice): void
    {
        if (! ($invoice->company?->auto_email_on_mydata_accept ?? false)) {
            return;
        }

        // G6: respect the per-customer opt-out (default true).
        if (! $invoice->customerAcceptsAutoEmail()) {
            return;
        }

        // NOTE on DB::afterCommit: Laravel fires the callback IMMEDIATELY when
        // there's no active transaction, so in a wrapping outer transaction (the
        // CreateInvoice path) it defers to that commit, and in the bare
        // ViewInvoice «Submit» path it runs inline — either way after the invoice
        // + mark row are durable. Defensive, correct in both cases.
        $invoiceId = $invoice->getKey();
        DB::afterCommit(function () use ($invoiceId): void {
            try {
                $fresh = Invoice::query()->whereKey($invoiceId)->first();
                if ($fresh) {
                    SendInvoiceEmail::dispatch($fresh);
                }
            } catch (Throwable $e) {
                Log::warning('SendInvoiceEmail auto-dispatch failed (filing succeeded)', [
                    'invoice_id' => $invoiceId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
