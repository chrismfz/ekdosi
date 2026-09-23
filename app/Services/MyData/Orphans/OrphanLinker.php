<?php

namespace App\Services\MyData\Orphans;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Models\Note;
use App\Models\Scopes\CompanyScope;
use App\Services\InvoiceBalance;
use App\Services\Whmcs\WhmcsWritebackService;
use App\Support\MyData\Codes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Σύνδεση με υπάρχον»: the operator says this myDATA orphan IS that local
 * invoice (filed, but its MARK never made it onto the record). We record the
 * MARK exactly as a filing would — the INSERT audit row in mydata_marks (the
 * source of truth) + the invoices.mydata_* mirror, in one transaction — without
 * re-sending anything to AADE and without emailing the customer (it is an old
 * document, not a new filing).
 */
final class OrphanLinker
{
    public function __construct(private readonly InvoiceBalance $balances) {}

    /**
     * Why this orphan can't be linked to this invoice (null = it can).
     *
     * @param  array<string, mixed>  $doc  MarkDetail::fromAadeDoc shape
     */
    public function blocker(Company $company, Invoice $invoice, array $doc): ?string
    {
        $mark = (string) ($doc['mark'] ?? '');
        $type = (string) ($doc['invoiceType'] ?? '');

        return match (true) {
            (int) $invoice->company_id !== (int) $company->getKey() => 'Το παραστατικό δεν ανήκει σε αυτή την εταιρεία.',
            $mark === '' => 'Λείπει το ΜΑΡΚ.',
            ($doc['direction'] ?? null) === 'inbound' => 'Είναι παραστατικό εξόδου — καταχωρίζεται από τα Έξοδα.',
            ($doc['state'] ?? 'VALID') !== 'VALID' => 'Είναι ακυρωμένο στο myDATA — δεν συνδέεται με ενεργό παραστατικό (μπορείς να το καταχωρίσεις ως ακυρωμένο).',
            filled($invoice->mydata_mark) => 'Το τοπικό παραστατικό έχει ήδη ΜΑΡΚ ('.$invoice->mydata_mark.').',
            $invoice->local_status === 'cancelled' => 'Το τοπικό παραστατικό είναι ακυρωμένο — επανέφερέ το πρώτα.',
            Codes::isCreditNoteType($type) !== $invoice->isCreditNote() => 'Το ένα είναι πιστωτικό και το άλλο όχι.',
            $this->markTaken($company, $mark) => 'Το ΜΑΡΚ είναι ήδη καταχωρισμένο σε άλλο τοπικό παραστατικό.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $doc  MarkDetail::fromAadeDoc shape
     */
    public function link(Company $company, Invoice $invoice, array $doc, ?int $userId): MyDataMark
    {
        $invoice->loadMissing(['customer', 'invoiceType', 'paymentMethod']);
        $mark = (string) $doc['mark'];

        $row = DB::transaction(function () use ($company, $invoice, $doc, $mark, $userId): MyDataMark {
            // Re-check under a lock: two operators can't link the same MARK twice.
            Invoice::query()->withoutGlobalScope(CompanyScope::class)->whereKey($invoice->getKey())->lockForUpdate()->first();
            if (($why = $this->blocker($company, $invoice->fresh(['customer', 'invoiceType']) ?? $invoice, $doc)) !== null) {
                throw new RuntimeException($why);
            }

            $row = MyDataMark::create([
                'company_id' => $company->getKey(),
                'invoice_id' => $invoice->getKey(),
                'mark' => $mark,
                'mydata_action' => 'INSERT',
                'invoice_url' => $doc['qrCodeUrl'] ?? null,
                'request' => null,
                'response' => self::evidence($doc, 'Συνδέθηκε χειροκίνητα από αδέσποτο myDATA (RequestTransmittedDocs).'),
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            $invoice->forceFill(array_merge($invoice->frozenPartyColumns(), [
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                'mydata_url' => ($doc['qrCodeUrl'] ?? null) ?: $invoice->mydata_url,
                'local_status' => $invoice->local_status === 'draft' ? 'active' : $invoice->local_status,
                'mydata_mark' => $mark,
                'mydata_pending_since' => null,
                // What AADE holds is the filed type — freeze it, like a filing.
                'mydata_type' => ($doc['invoiceType'] ?? null) ?: $invoice->invoiceType?->mydata_type,
            ]))->save();

            Note::create([
                'company_id' => $company->getKey(),
                'notable_type' => Invoice::class,
                'notable_id' => $invoice->getKey(),
                'body' => 'Συνδέθηκε με το ΜΑΡΚ '.$mark.' από τα αδέσποτα του myDATA'
                    .(filled($doc['invcode'] ?? null) ? ' (στο myDATA: '.$doc['invcode'].')' : '').'.',
                'author_user_id' => $userId,
            ]);

            return $row;
        });

        $this->balances->recompute($invoice->fresh());
        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice->fresh(), $mark);

        return $row;
    }

    private function markTaken(Company $company, string $mark): bool
    {
        return Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('mydata_mark', $mark)
            ->exists();
    }

    /** The AADE document we relied on, kept as the audit evidence. */
    public static function evidence(array $doc, string $headline): string
    {
        $xml = (string) ($doc['responseXml'] ?? '');

        return $headline.' uid='.(($doc['uid'] ?? null) ?: '?').($xml !== '' ? "\n".$xml : '');
    }
}
