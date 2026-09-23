<?php

namespace App\Services\MyData\Orphans;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Note;
use App\Models\PaymentMethod;
use App\Models\Scopes\CompanyScope;
use App\Services\InvoiceBalance;
use App\Services\RecomputeInvoiceTotals;
use App\Support\MyData\Codes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Καταχώριση τοπικά»: turn a myDATA orphan (a sale filed under our ΑΦΜ from
 * another system, or whose local record was lost) into a local invoice AS
 * FILED — the income-side mirror of ExpenseImporter.
 *
 * myDATA carries no descriptions, so each AADE line becomes one local line:
 * its net value at its VAT rate (+ the §8.3 exemption and the E3 income
 * classification it was filed with), described «Γραμμή Ν (από myDATA)». The
 * document keeps its AADE series/ΑΑ, MARK and QR, is recorded as already filed
 * (never re-sent), and its net, VAT and gross must each agree with AADE's to the
 * cent — exactly — or nothing is written. Filed under one
 * of OUR series, it must go under that series' own type, and that counter moves
 * past its ΑΑ. Deliberately out of scope (refused with the reason): credit notes
 * (they need the original), withholding/fees/other taxes, a non-numeric ΑΑ.
 */
final class OrphanImporter
{
    public function __construct(
        private readonly RecomputeInvoiceTotals $totals,
        private readonly InvoiceBalance $balances,
    ) {}

    /**
     * Why this orphan can't be imported at all (null = it can).
     *
     * @param  array<string, mixed>  $doc  MarkDetail::fromAadeDoc shape
     */
    public function blocker(Company $company, array $doc): ?string
    {
        $type = (string) ($doc['invoiceType'] ?? '');
        $aa = trim((string) ($doc['aa'] ?? ''));
        $net = (float) ($doc['netTotal'] ?? 0);
        $vat = (float) ($doc['vatTotal'] ?? 0);
        $gross = (float) ($doc['grossTotal'] ?? 0);

        return match (true) {
            blank($doc['mark'] ?? null) => 'Λείπει το ΜΑΡΚ.',
            ($doc['direction'] ?? null) === 'inbound' => 'Είναι παραστατικό εξόδου — καταχωρίζεται από τα Έξοδα.',
            ! OrphanParty::issuedByUs($company, $doc) => 'Δεν εκδόθηκε με το ΑΦΜ μας — δεν είναι δική μας πώληση.',
            Codes::transmittedDocBucket($type) !== 'income' => 'Δεν είναι παραστατικό πώλησης ('.($type ?: '—').').',
            Codes::isCreditNoteType($type) => 'Τα πιστωτικά καταχωρίζονται χειροκίνητα (χρειάζονται το αρχικό παραστατικό).',
            $aa === '' || ! ctype_digit($aa) => 'Ο ΑΑ «'.$aa.'» δεν είναι αριθμός — καταχώρισέ το χειροκίνητα.',
            self::cents($net + $vat) !== self::cents($gross) => 'Έχει παρακρατήσεις / τέλη / λοιπούς φόρους — καταχώρισέ το χειροκίνητα.',
            ($doc['lines'] ?? []) === [] => 'Δεν έχει γραμμές.',
            ($bad = $this->unsupportedLine($doc)) !== null => $bad,
            OrphanParty::markTaken($company, (string) $doc['mark']) => 'Το ΜΑΡΚ είναι ήδη καταχωρισμένο σε τοπικό παραστατικό.',
            ($dup = $this->existingNumber($company, $doc)) !== null => "Υπάρχει ήδη τοπικό {$dup->invcode} με την ίδια σειρά/ΑΑ — αν είναι το ίδιο, χρησιμοποίησε «Σύνδεση».",
            default => null,
        };
    }

    /**
     * The tenant's type that OWNS the document's series (its code = the series),
     * if any — such a document must be imported under it.
     */
    public static function seriesType(Company $company, array $doc): ?InvoiceType
    {
        $series = trim((string) ($doc['series'] ?? ''));

        return $series === '' ? null : InvoiceType::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())->where('code', $series)->first();
    }

    /** The local code the document gets: «ΑΠΥ90» under its own series, else «0 62». */
    public static function invcodeFor(array $doc, InvoiceType $type): string
    {
        $series = trim((string) ($doc['series'] ?? ''));
        $aa = self::number($doc);

        return $series !== '' && $series === (string) $type->code ? $series.$aa : trim($series.' '.$aa);
    }

    /** The ΑΑ as the number it is («062» → «62», the way `code` stores it). */
    public static function number(array $doc): string
    {
        $aa = trim((string) ($doc['aa'] ?? ''));

        return ctype_digit($aa) ? (string) (int) $aa : $aa;
    }

    /**
     * @param  array<string, mixed>  $doc  MarkDetail::fromAadeDoc shape
     */
    public function import(Company $company, array $doc, InvoiceType $type, ?Customer $customer, PaymentMethod $paymentMethod, ?int $userId): Invoice
    {
        foreach ([$type, $paymentMethod, $customer] as $owned) {
            if ($owned !== null && (int) $owned->company_id !== (int) $company->getKey()) {
                throw new RuntimeException('Άκυρη επιλογή.');
            }
        }
        $owner = self::seriesType($company, $doc);
        if ($owner !== null && (int) $owner->getKey() !== (int) $type->getKey()) {
            throw new RuntimeException('Η σειρά «'.$owner->code.'» ανήκει στον τύπο «'.$owner->code.' — '.$owner->name.'» — διάλεξέ τον.');
        }
        // The local type must BE the filed kind of document (retail vs B2B, credit…):
        // the invoice logic reads it (counterpart freeze, credit sign).
        if ($type->is_credit || (string) $type->mydata_type !== (string) ($doc['invoiceType'] ?? '')) {
            throw new RuntimeException('Ο τύπος «'.$type->code.'» ('.($type->mydata_type ?: '—').') δεν είναι ο τύπος που δηλώθηκε ('.($doc['invoiceType'] ?? '—').').');
        }
        if (OrphanParty::counterpartKeys($doc) !== [] && ! OrphanParty::isCounterpart($doc, $customer?->afm)) {
            throw new RuntimeException('Διάλεξε τον πελάτη με ΑΦΜ '.$doc['counterpartVat'].' (τον αντισυμβαλλόμενο του myDATA).');
        }

        $series = trim((string) ($doc['series'] ?? ''));
        $aa = (int) ($doc['aa'] ?? 0);
        $cancelled = ($doc['state'] ?? 'VALID') === 'CANCELLED';
        $mark = (string) ($doc['mark'] ?? '');

        $invoice = DB::transaction(function () use ($company, $doc, $type, $customer, $paymentMethod, $userId, $series, $aa, $cancelled, $mark): Invoice {
            // Serialise per tenant, then re-check everything on fresh data: two
            // imports of one orphan can't both write its MARK or its number.
            OrphanParty::lockTenant($company);
            // Lock the type (the counter InvoiceNumberer allocates from) BEFORE the
            // number re-check — a native issue can't take this ΑΑ in between.
            $lockedType = InvoiceType::query()->withoutGlobalScope(CompanyScope::class)->whereKey($type->getKey())->lockForUpdate()->firstOrFail();
            if (($why = $this->blocker($company, $doc)) !== null) {
                throw new RuntimeException($why);
            }
            // Filed under OUR series (invcount = the NEXT number): never hand this ΑΑ out again.
            if ($series !== '' && $series === (string) $lockedType->code && (int) $lockedType->invcount <= $aa) {
                $lockedType->forceFill(['invcount' => $aa + 1])->save();
            }

            // Created as a DRAFT and issued only once its lines and totals are in
            // place — so everything that reacts to an issue (balance snapshot, …)
            // sees the finished document, not an empty one.
            $invoice = Invoice::create([
                'company_id' => $company->getKey(),
                'invoice_type_id' => $type->getKey(),
                'customer_id' => $customer?->getKey(),
                'payment_method_id' => $paymentMethod->getKey(),
                'invcode' => self::invcodeFor($doc, $type),
                'series' => $series !== '' ? $series : null,
                'code' => $aa,
                'issued_at' => OrphanMatcher::issueDate($doc) ?? now(),
                'local_status' => 'draft',
                'header_discount_percent' => 0,
            ]);

            foreach (array_values($doc['lines']) as $i => $line) {
                $category = (int) $line['vatCategory'];
                $class = $line['classifications'][0] ?? null;
                InvoiceLine::create([
                    'company_id' => $company->getKey(),
                    'invoice_id' => $invoice->getKey(),
                    'qty' => 1,
                    'price_per_item' => round((float) $line['netValue'], 2),
                    'discount' => 0,
                    'vat_percent' => Codes::VAT_CATEGORY_RATES[$category],
                    'vat_exemption_category' => $line['vatExemptionCategory'] ?? null,
                    'mydata_income_class' => $class['type'] ?? null,
                    'mydata_income_class_category' => $class['category'] ?? null,
                    'product_descr' => filled($line['itemDescr'] ?? null)
                        ? (string) $line['itemDescr']
                        : 'Γραμμή '.($line['lineNumber'] ?? $i + 1).' (από myDATA)',
                ]);
            }

            ($this->totals)($invoice);
            $invoice->refresh();
            $local = ['net' => (float) $invoice->net_total, 'vat' => (float) $invoice->gross_total - (float) $invoice->net_total, 'gross' => (float) $invoice->gross_total];
            $aade = ['net' => (float) ($doc['netTotal'] ?? 0), 'vat' => (float) ($doc['vatTotal'] ?? 0), 'gross' => (float) ($doc['grossTotal'] ?? 0)];
            foreach (['net' => 'Καθαρή αξία', 'vat' => 'ΦΠΑ', 'gross' => 'Σύνολο'] as $k => $label) {
                if (self::cents($local[$k]) !== self::cents($aade[$k])) {   // exactly, to the cent
                    throw new RuntimeException('Διαφορά με το myDATA στο «'.$label.'» (τοπικά '.number_format($local[$k], 2, ',', '.')
                        .' € / myDATA '.number_format($aade[$k], 2, ',', '.').' €) — καταχώρισέ το χειροκίνητα.');
                }
            }

            $invoice->loadMissing(['customer', 'invoiceType']);
            $invoice->forceFill(array_merge($invoice->frozenPartyColumns(), [
                'local_status' => $cancelled ? 'cancelled' : 'active',
                'mydata_sent' => true,
                'mydata_state' => $cancelled ? 'CANCELLED' : 'VALID',
                'mydata_mark' => $mark,
                'mydata_url' => $doc['qrCodeUrl'] ?? null,
                'mydata_type' => $doc['invoiceType'] ?? null,
                // History, not a new issue: no balance snapshot, no reminders.
                'origin' => Invoice::ORIGIN_MYDATA_ORPHAN,
            ]))->save();

            MyDataMark::create([
                'company_id' => $company->getKey(),
                'invoice_id' => $invoice->getKey(),
                'mark' => $mark,
                'mydata_action' => 'INSERT',
                'invoice_url' => $doc['qrCodeUrl'] ?? null,
                'request' => null,
                'response' => OrphanLinker::evidence($doc, 'Καταχωρίστηκε από αδέσποτο myDATA (RequestTransmittedDocs).'),
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);
            if ($cancelled) {
                MyDataMark::create([
                    'company_id' => $company->getKey(),
                    'invoice_id' => $invoice->getKey(),
                    'mark' => $mark,
                    'cancellation_mark' => $doc['cancelledByMark'] ?? null,
                    'mydata_action' => 'CANCEL',
                    'response' => 'Ακυρωμένο ήδη στο myDATA κατά την καταχώριση.',
                    'mark_date' => now()->toDateString(),
                    'mark_time' => now()->toTimeString(),
                ]);
            }

            Note::create([
                'company_id' => $company->getKey(),
                'notable_type' => Invoice::class,
                'notable_id' => $invoice->getKey(),
                'body' => 'Καταχωρίστηκε από τα αδέσποτα του myDATA (ΜΑΡΚ '.$mark.'). Οι γραμμές είναι όπως δηλώθηκαν στο myDATA (χωρίς περιγραφές).',
                'author_user_id' => $userId,
            ]);

            return $invoice;
        });

        $this->balances->recompute($invoice->fresh());

        return $invoice->fresh();
    }

    /**
     * A local invoice (deleted too) already carrying this series/ΑΑ — under
     * either code form («ΑΠΥ90» / «ΑΠΥ 90») or its filed series + number.
     */
    private function existingNumber(Company $company, array $doc): ?Invoice
    {
        $series = trim((string) ($doc['series'] ?? ''));
        $aa = self::number($doc);
        if ($aa === '' || ! ctype_digit($aa)) {
            return null;
        }

        return Invoice::query()->withoutGlobalScope(CompanyScope::class)->withTrashed()
            ->where('company_id', $company->getKey())
            ->where(fn ($q) => $q->whereIn('invcode', array_unique([$series.$aa, trim($series.' '.$aa)]))->orWhere('code', (int) $aa))
            ->with('invoiceType:id,code')
            ->get()
            ->first(fn (Invoice $i): bool => in_array((string) $i->invcode, [$series.$aa, trim($series.' '.$aa)], true)
                || ((string) $i->code === $aa && trim((string) $i->filedSeries()) === $series));
    }

    /** An amount in whole cents — legal totals are compared exactly. */
    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** A line we can't reproduce locally (unknown VAT category / no-VAT record). */
    private function unsupportedLine(array $doc): ?string
    {
        foreach ($doc['lines'] as $line) {
            $category = $line['vatCategory'] ?? null;
            if ($category === null || ! array_key_exists((int) $category, Codes::VAT_CATEGORY_RATES) || Codes::VAT_CATEGORY_RATES[(int) $category] === null) {
                return 'Γραμμή με κατηγορία ΦΠΑ «'.($category ?? '—').'» που δεν υποστηρίζεται — καταχώρισέ το χειροκίνητα.';
            }
        }

        return null;
    }
}
