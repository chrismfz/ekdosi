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
 * (never re-sent), and its totals must agree with AADE's or nothing is written.
 * Deliberately out of scope (refused with the reason): credit notes (they need
 * the original), documents with withholding/fees/other taxes, a non-numeric ΑΑ.
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
            Codes::transmittedDocBucket($type) !== 'income' => 'Δεν είναι παραστατικό πώλησης ('.($type ?: '—').').',
            Codes::isCreditNoteType($type) => 'Τα πιστωτικά καταχωρίζονται χειροκίνητα (χρειάζονται το αρχικό παραστατικό).',
            $aa === '' || ! ctype_digit($aa) => 'Ο ΑΑ «'.$aa.'» δεν είναι αριθμός — καταχώρισέ το χειροκίνητα.',
            abs(round($net + $vat, 2) - round($gross, 2)) > 0.02 => 'Έχει παρακρατήσεις / τέλη / λοιπούς φόρους — καταχώρισέ το χειροκίνητα.',
            ($doc['lines'] ?? []) === [] => 'Δεν έχει γραμμές.',
            ($bad = $this->unsupportedLine($doc)) !== null => $bad,
            Invoice::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $company->getKey())
                ->where('mydata_mark', (string) $doc['mark'])->exists() => 'Το ΜΑΡΚ είναι ήδη καταχωρισμένο σε τοπικό παραστατικό.',
            default => null,
        };
    }

    /** The local code the document gets: «ΑΠΥ90» under its own series, else «0 62». */
    public static function invcodeFor(array $doc, InvoiceType $type): string
    {
        $series = trim((string) ($doc['series'] ?? ''));
        $aa = trim((string) ($doc['aa'] ?? ''));

        return $series !== '' && $series === (string) $type->code ? $series.$aa : trim($series.' '.$aa);
    }

    /**
     * @param  array<string, mixed>  $doc  MarkDetail::fromAadeDoc shape
     */
    public function import(Company $company, array $doc, InvoiceType $type, ?Customer $customer, PaymentMethod $paymentMethod, ?int $userId): Invoice
    {
        if (($why = $this->blocker($company, $doc)) !== null) {
            throw new RuntimeException($why);
        }
        foreach ([$type, $paymentMethod, $customer] as $owned) {
            if ($owned !== null && (int) $owned->company_id !== (int) $company->getKey()) {
                throw new RuntimeException('Άκυρη επιλογή.');
            }
        }
        $counterpartVat = self::normalVat($doc['counterpartVat'] ?? null);
        if ($counterpartVat !== null && ($customer === null || self::normalVat($customer->afm) !== $counterpartVat)) {
            throw new RuntimeException('Διάλεξε τον πελάτη με ΑΦΜ '.$doc['counterpartVat'].' (τον αντισυμβαλλόμενο του myDATA).');
        }

        $invcode = self::invcodeFor($doc, $type);
        $series = trim((string) ($doc['series'] ?? ''));
        $aa = (int) $doc['aa'];
        $cancelled = ($doc['state'] ?? 'VALID') === 'CANCELLED';
        $mark = (string) $doc['mark'];

        $invoice = DB::transaction(function () use ($company, $doc, $type, $customer, $paymentMethod, $userId, $invcode, $series, $aa, $cancelled, $mark): Invoice {
            $lockedType = InvoiceType::query()->withoutGlobalScope(CompanyScope::class)->whereKey($type->getKey())->lockForUpdate()->firstOrFail();
            if (Invoice::query()->withoutGlobalScope(CompanyScope::class)->withTrashed()
                ->where('company_id', $company->getKey())->where('invcode', $invcode)->exists()) {
                throw new RuntimeException("Υπάρχει ήδη τοπικό παραστατικό {$invcode} — αν είναι το ίδιο, χρησιμοποίησε «Σύνδεση».");
            }
            // Filed under OUR series: the counter must never hand this ΑΑ out again.
            if ($series !== '' && $series === (string) $lockedType->code && (int) $lockedType->invcount <= $aa) {
                $lockedType->forceFill(['invcount' => $aa + 1])->save();
            }

            $invoice = Invoice::create([
                'company_id' => $company->getKey(),
                'invoice_type_id' => $type->getKey(),
                'customer_id' => $customer?->getKey(),
                'payment_method_id' => $paymentMethod->getKey(),
                'invcode' => $invcode,
                'series' => $series !== '' ? $series : null,
                'code' => $aa,
                'issued_at' => OrphanMatcher::issueDate($doc) ?? now(),
                'local_status' => $cancelled ? 'cancelled' : 'active',
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
            $gross = round((float) ($doc['grossTotal'] ?? 0), 2);
            if (abs((float) $invoice->gross_total - $gross) > 0.02) {
                throw new RuntimeException('Τα σύνολα δεν συμφωνούν με το myDATA (τοπικά '.number_format((float) $invoice->gross_total, 2, ',', '.')
                    .' € / myDATA '.number_format($gross, 2, ',', '.').' €) — καταχώρισέ το χειροκίνητα.');
            }

            $invoice->loadMissing(['customer', 'invoiceType']);
            $invoice->forceFill(array_merge($invoice->frozenPartyColumns(), [
                'mydata_sent' => true,
                'mydata_state' => $cancelled ? 'CANCELLED' : 'VALID',
                'mydata_mark' => $mark,
                'mydata_url' => $doc['qrCodeUrl'] ?? null,
                'mydata_type' => $doc['invoiceType'] ?? null,
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

    /** ΑΦΜ / VAT number compared without spaces, dots or a country prefix. */
    public static function normalVat(mixed $vat): ?string
    {
        $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($vat ?? '')) ?? '');
        $v = preg_replace('/^(EL|GR|[A-Z]{2})(?=\d)/', '', $v) ?? $v;

        return $v === '' ? null : $v;
    }
}
