<?php

namespace App\Services\MyData\Orphans;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Models\Note;
use App\Models\Scopes\CompanyScope;
use App\Services\InvoiceBalance;
use App\Services\Whmcs\WhmcsWritebackService;
use App\Support\Money;
use App\Support\MyData\Codes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Σύνδεση με υπάρχον»: this myDATA orphan IS that local invoice — issued here
 * under the same series/ΑΑ, but its MARK never made it onto the record (a lost
 * response, a restored backup). We record the MARK exactly as a filing would —
 * the INSERT audit row in mydata_marks (the source of truth) + the
 * invoices.mydata_* mirror, in one transaction — without re-sending anything to
 * AADE and without emailing the customer.
 *
 * Only the SAME document can be linked: our issue, an issued (non-draft,
 * non-cancelled) local invoice with the same series & ΑΑ, issue date, myDATA
 * type, counterpart and amount, with no submission of it in flight. Anything else is a different legal document — a likely
 * double issue to look at, never a link.
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
        $series = trim((string) ($doc['series'] ?? ''));
        $aa = OrphanImporter::number($doc);
        $counterparty = filled($doc['counterpartVat'] ?? null);
        $date = OrphanMatcher::issueDate($doc);
        $localType = (string) ($invoice->mydata_type ?: $invoice->invoiceType?->mydata_type);

        return match (true) {
            (int) $invoice->company_id !== (int) $company->getKey() => 'Το παραστατικό δεν ανήκει σε αυτή την εταιρεία.',
            $mark === '' => 'Λείπει το ΜΑΡΚ.',
            ! OrphanParty::issuedByUs($company, $doc) => 'Δεν εκδόθηκε με το ΑΦΜ μας — δεν είναι δική μας πώληση.',
            ($doc['state'] ?? 'VALID') !== 'VALID' => 'Είναι ακυρωμένο στο myDATA — δεν συνδέεται με ενεργό παραστατικό (μπορείς να το καταχωρίσεις ως ακυρωμένο).',
            filled($invoice->mydata_mark) => 'Το τοπικό παραστατικό έχει ήδη ΜΑΡΚ ('.$invoice->mydata_mark.').',
            $invoice->local_status === 'draft' => 'Το τοπικό είναι πρόχειρο (δεν έχει εκδοθεί) — δεν μπορεί να είναι το ίδιο παραστατικό.',
            $invoice->local_status === 'cancelled' => 'Το τοπικό παραστατικό είναι ακυρωμένο — επανέφερέ το πρώτα.',
            (string) $invoice->code !== $aa || trim((string) $invoice->filedSeries()) !== $series => 'Άλλη σειρά/ΑΑ ('.$invoice->invcode.' ≠ '.($doc['invcode'] ?? '—').') — είναι άλλο παραστατικό.',
            Codes::transmittedDocBucket($type) !== 'income' => 'Δεν είναι παραστατικό πώλησης ('.($type ?: '—').').',
            Codes::isCreditNoteType($type) !== $invoice->isCreditNote() => 'Το ένα είναι πιστωτικό και το άλλο όχι.',
            $localType !== '' && $localType !== $type => 'Άλλος τύπος myDATA ('.$localType.' τοπικά / '.$type.' στο myDATA).',
            $date === null || $invoice->issued_at === null || ! $invoice->issued_at->isSameDay($date) => 'Άλλη ημερομηνία έκδοσης ('
                .($invoice->issued_at?->format('d/m/Y') ?? '—').' τοπικά / '.($doc['issuedAtHuman'] ?? '—').' στο myDATA).',
            $invoice->mydata_pending_since !== null => 'Εκκρεμεί υποβολή του τοπικού στο myDATA — περίμενε να ολοκληρωθεί.',
            $counterparty && ! OrphanParty::isCounterpart($doc, $invoice->vat_no) && ! OrphanParty::isCounterpart($doc, $invoice->customer?->afm) => 'Άλλος αντισυμβαλλόμενος (ΑΦΜ '.$doc['counterpartVat'].' στο myDATA).',
            Money::differsByCent((float) $invoice->gross_total, (float) ($doc['grossTotal'] ?? 0)) => 'Άλλο σύνολο (τοπικά '
                .number_format((float) $invoice->gross_total, 2, ',', '.').' € / myDATA '.number_format((float) ($doc['grossTotal'] ?? 0), 2, ',', '.').' €).',
            OrphanParty::markTaken($company, $mark) => 'Το ΜΑΡΚ είναι ήδη καταχωρισμένο σε άλλο τοπικό παραστατικό.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $doc  MarkDetail::fromAadeDoc shape
     */
    public function link(Company $company, Invoice $invoice, array $doc, ?int $userId): MyDataMark
    {
        $mark = (string) ($doc['mark'] ?? '');

        // The submitter's own per-invoice lock: never while a real submission of
        // this invoice is in flight (its response would write a second MARK).
        $lock = Cache::lock('mydata-submit:'.$invoice->getKey(), 120);
        if (! $lock->get()) {
            throw new RuntimeException('Το παραστατικό υποβάλλεται αυτή τη στιγμή στο myDATA — δοκίμασε σε λίγο.');
        }

        try {
            $row = $this->linkLocked($company, $invoice, $doc, $mark, $userId);
        } finally {
            $lock->release();
        }

        $this->balances->recompute($invoice->fresh());
        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice->fresh(), $mark);

        return $row;
    }

    private function linkLocked(Company $company, Invoice $invoice, array $doc, string $mark, ?int $userId): MyDataMark
    {
        return DB::transaction(function () use ($company, $invoice, $doc, $mark, $userId): MyDataMark {
            // Serialise per tenant, then re-check on fresh data: two operators
            // can't record one MARK twice.
            OrphanParty::lockTenant($company);
            $fresh = Invoice::query()->withoutGlobalScope(CompanyScope::class)
                ->with(['customer', 'invoiceType', 'paymentMethod'])
                ->whereKey($invoice->getKey())->lockForUpdate()->first();
            if ($fresh === null) {
                throw new RuntimeException('Το παραστατικό δεν βρέθηκε.');
            }
            if (($why = $this->blocker($company, $fresh, $doc)) !== null) {
                throw new RuntimeException($why);
            }

            $row = MyDataMark::create([
                'company_id' => $company->getKey(),
                'invoice_id' => $fresh->getKey(),
                'mark' => $mark,
                'mydata_action' => 'INSERT',
                'invoice_url' => $doc['qrCodeUrl'] ?? null,
                'request' => null,
                'response' => self::evidence($doc, 'Συνδέθηκε χειροκίνητα από αδέσποτο myDATA (RequestTransmittedDocs).'),
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // local_status stays «active» (a draft can't be linked), so none of
            // the first-issue side effects (stock, service renewal) re-fire.
            $fresh->forceFill(array_merge($fresh->frozenPartyColumns(), [
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                'mydata_url' => ($doc['qrCodeUrl'] ?? null) ?: $fresh->mydata_url,
                'mydata_mark' => $mark,
                'mydata_pending_since' => null,
                // What AADE holds is the filed type — freeze it, like a filing.
                'mydata_type' => ($doc['invoiceType'] ?? null) ?: $fresh->invoiceType?->mydata_type,
            ]))->save();

            Note::create([
                'company_id' => $company->getKey(),
                'notable_type' => Invoice::class,
                'notable_id' => $fresh->getKey(),
                'body' => 'Συνδέθηκε με το ΜΑΡΚ '.$mark.' από τα αδέσποτα του myDATA.',
                'author_user_id' => $userId,
            ]);

            return $row;
        });
    }

    /** The AADE document we relied on, kept as the audit evidence. */
    public static function evidence(array $doc, string $headline): string
    {
        $xml = (string) ($doc['responseXml'] ?? '');

        return $headline.' uid='.(($doc['uid'] ?? null) ?: '?').($xml !== '' ? "\n".$xml : '');
    }
}
