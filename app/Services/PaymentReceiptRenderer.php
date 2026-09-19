<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Payment;
use App\Support\CustomerLanguage;
use App\Support\Pdf\PdfLabels;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders an INFORMAL «Απόδειξη Είσπραξης» PDF for one είσπραξη — the group of
 * Payment rows that share a `reference` (e.g. a gateway settlement that split
 * across invoices + on-account, or a single manual receipt). NOT a tax document
 * (no myDATA / MARK): a printable acknowledgement of money received, with the
 * channel («Πύλη · Eurobank») and the acquirer txn id so «πλήρωσα, δώσε χαρτί»
 * is one click.
 *
 * Plain service (not queued): the download action runs it inline.
 */
class PaymentReceiptRenderer
{
    private const RENDER_MEMORY_LIMIT = '512M';

    private const RENDER_TIME_LIMIT_SECONDS = 60;

    public function render(Payment $payment): string
    {
        $payment->loadMissing(['company', 'customer', 'paymentMethod', 'paymentIntent']);

        // i18n: a receipt is an informal per-customer acknowledgement (not a frozen
        // legal document), so it follows the customer's current language; with no
        // linked customer, fall back to the tenant's default (then its country).
        $customer = $payment->customer;
        $L = PdfLabels::for($customer
            ? CustomerLanguage::forCustomer($customer)
            : PdfLabels::resolveLanguage($payment->company?->default_language, $payment->company?->country_code));

        // The full είσπραξη: every INCOMING row of THIS customer sharing this
        // reference (a refund is never part of a receipt). Scoped to the customer
        // too — `reference` is not customer-unique (an ETL import can reuse a bank
        // ref), so without it a shared string could pull a DIFFERENT customer's
        // payment into this receipt. A null reference = just this one row.
        $rows = Payment::query()
            ->where('company_id', $payment->company_id)
            ->where('customer_id', $payment->customer_id)
            ->where('kind', 'payment')
            ->when(
                filled($payment->reference),
                fn ($q) => $q->where('reference', $payment->reference),
                fn ($q) => $q->whereKey($payment->id),
            )
            ->with('invoice:id,invcode')
            ->orderBy('id')
            ->get();

        $lines = $rows->map(fn (Payment $p): array => [
            'label' => $p->invoice?->invcode ?: $L('on_account'),
            'amount' => (float) $p->amount,
        ])->all();

        $total = round($rows->sum(fn (Payment $p): float => (float) $p->amount), 2);

        $previousMemory = ini_get('memory_limit');
        $previousTime = ini_get('max_execution_time');

        try {
            @ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
            @set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);

            return Pdf::loadView('receipts.pdf', [
                'tenant' => $payment->company,
                'logoDataUri' => $this->loadLogoDataUri($payment->company),
                'customerName' => $payment->customer?->name ?? '—',
                'customerAfm' => $payment->customer?->afm,
                'reference' => $payment->reference,
                'date' => optional($payment->pay_date)->format('d/m/Y') ?? now()->format('d/m/Y'),
                'channel' => $payment->channelLabel(),
                'method' => $payment->paymentMethod?->description,
                'transactionId' => $payment->transaction_id,
                'lines' => $lines,
                'total' => $total,
                'L' => $L,
            ])
                ->setPaper('A4', 'portrait')
                ->output();
        } finally {
            @ini_set('memory_limit', $previousMemory);
            @set_time_limit((int) $previousTime);
        }
    }

    private function loadLogoDataUri(?Company $tenant): ?string
    {
        if (! $tenant || empty($tenant->logo_path)) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($tenant->logo_path)) {
                return null;
            }
            $bytes = $disk->get($tenant->logo_path);
        } catch (\Throwable $e) {
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
