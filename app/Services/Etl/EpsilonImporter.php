<?php

namespace App\Services\Etl;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MetricUnit;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\InvoiceBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Import Epsilon Smart (Τιμολόγηση) JSON exports into ekdosi.
 *
 * Phase 1: Customers + Items/Services (→ products). Sales (→ invoices) is a
 * separate phase. Re-runnable / upsert by NATURAL KEY (Epsilon has no stable
 * surrogate id in these exports): customers by ΑΦΜ (TIN), products by Name.
 * A re-run updates the legacy-sourced columns and never duplicates.
 *
 * Lookups (VAT category, metric unit, product category, payment method) resolve
 * against the tenant's existing rows — these map cleanly onto the standard AADE
 * lookups the fresh-install seeder installs (MyDataLookupSeeder). Missing metric
 * units / product categories are created on the fly; VAT falls back to the
 * tenant default. All queries are explicitly company-scoped (works in a job /
 * CLI with no ambient tenant), and bypass the global scope so the explicit
 * company_id is authoritative.
 */
class EpsilonImporter
{
    private int $companyId;

    /** @var array<string,int> VtclName → ekdosi VAT rate */
    private const VAT_CLASS_RATES = [
        'Κανονικός' => 24,
        'Μειωμένος' => 13,
        'Υπερμειωμένος' => 6,
        'Απαλλασσόμενο' => 0,
    ];

    /** @var array<string,string> Epsilon unit name → ekdosi metric-unit name */
    private const UNIT_MAP = [
        'Τεμάχιο' => 'ΤΕΜ',
        'Ώρα' => 'ΩΡΑ',
        'Μέτρο' => 'ΜΕΤΡΟ',
        'Κιλό' => 'ΚΙΛΟ',
        'Λίτρο' => 'ΛΙΤΡΟ',
    ];

    /** @var array<string,string> Epsilon accounting category → ekdosi product category */
    private const CATEGORY_MAP = [
        'Εμπόρευμα' => 'Εμπορεύματα',
        'Υπηρεσία' => 'Υπηρεσίες',
        'Προϊόν' => 'Προϊόντα',
    ];

    /** @var array<int,int> rate → vat_category_id (memoised) */
    private array $vatCache = [];

    /** @var array<string,int> metric-unit name → id (memoised) */
    private array $unitCache = [];

    /** @var array<string,int> product-category description_short → id (memoised) */
    private array $categoryCache = [];

    /** @var array<string,?int> payment-method description → id (memoised) */
    private array $paymentCache = [];

    /** @var array<string,int> invoice-type code → id (memoised) */
    private array $invoiceTypeCache = [];

    /** @var array<string,int> customer ΑΦΜ → id (memoised) */
    private array $customerCache = [];

    /** @var array<string,?int> product description_short → id (memoised) */
    private array $productByNameCache = [];

    private ?int $defaultVatId = null;

    /** Marker note on the synthetic settlement payment (idempotent re-runs). */
    private const IMPORT_PAYMENT_NOTE = 'Εισαγωγή ιστορικού Epsilon — εξοφλημένο κατά την έκδοση';

    /** @var array<string,true> de-duped human-readable warnings surfaced to the operator */
    private array $warnings = [];

    public function __construct(Company $tenant)
    {
        $this->companyId = $tenant->getKey();
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_keys($this->warnings);
    }

    private function warn(string $message): void
    {
        $this->warnings[$message] = true;
    }

    /**
     * Import customers + products in one pass.
     *
     * @param  array{customers?: array, items?: array, services?: array}  $payload
     * @return array<string, array{created:int, updated:int, skipped:int}>
     */
    public function import(array $payload): array
    {
        $out = [];
        if (isset($payload['customers'])) {
            $out['customers'] = $this->importCustomers($payload['customers']);
        }
        if (isset($payload['items']) || isset($payload['services'])) {
            $out['products'] = $this->importProducts(
                $payload['items'] ?? [],
                $payload['services'] ?? [],
            );
        }
        if (isset($payload['sales'])) {
            $out['sales'] = $this->importSales($payload['sales']);
        }

        return $out;
    }

    /**
     * Sales → invoices (historical, already filed at AADE). Each Epsilon sale is
     * a posted ΤΙΜ with a MARK; we land it as an `active` invoice carrying the
     * VALID myDATA state + MARK (cache columns) and a minimal `mydata_marks`
     * audit row. The Epsilon document number (DocNum) is preserved as the ΑΑ
     * (invcode = series+DocNum), and the invoice-type counter is bumped so new
     * ekdosi invoices continue from there.
     *
     * Re-runnable: matched by (company_id, invcode); a re-run refreshes the
     * header and REPLACES the lines + mark row (they have no natural key).
     * counterpart customer + invoice type resolve-or-create (warned).
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array{created:int, updated:int, skipped:int}
     */
    public function importSales(array $rows): array
    {
        $created = $updated = $skipped = 0;
        $counterByType = []; // type id → max DocNum seen (to bump invcount)

        DB::transaction(function () use ($rows, &$created, &$updated, &$skipped, &$counterByType) {
            $now = now();
            foreach ($rows as $sale) {
                $docNum = (int) ($sale['DocNum'] ?? 0);
                $series = $this->clean($sale['DocSeriesShortcut'] ?? null);
                if ($docNum <= 0 || $series === null) {
                    $this->warn('Πώληση χωρίς DocNum/σειρά — παραλείφθηκε.');
                    $skipped++;

                    continue;
                }

                $typeId = $this->resolveInvoiceType($series, $this->clean($sale['DocSerie'] ?? null) ?? $series);
                $customerId = $this->resolveSaleCustomer($sale);
                $invcode = $series.$docNum;
                $issuedAt = $this->parseDateTime($sale['CreationTime'] ?? null, $sale['Date'] ?? null);
                $mark = $this->cleanMark($sale['Mark'] ?? null);
                $paymentMethodId = $this->resolvePaymentMethod($this->clean($sale['PmtMethod'] ?? null));
                $gross = round((float) ($sale['TotalVal'] ?? 0), 2);

                $header = [
                    'invoice_type_id' => $typeId,
                    'customer_id' => $customerId,
                    'code' => $docNum,
                    'issued_at' => $issuedAt,
                    'payment_method_id' => $paymentMethodId,
                    'header_discount_percent' => 0,
                    'net_total' => round((float) ($sale['NetVal'] ?? 0), 2),
                    'gross_total' => $gross,
                    // Party snapshot (frozen at issue — exactly how AADE has it).
                    // Truncated to the column widths: strict mode would abort the
                    // whole import on an over-length Greek ΑΕ name (already 100ch
                    // in the sample) rather than skip the row.
                    'company_name' => $this->cut($this->clean($sale['TraderName'] ?? null), 120),
                    'vat_no' => $this->cut($this->clean($sale['TraderTIN'] ?? null), 20),
                    'address1' => $this->cut($this->join([$sale['TraderStreet'] ?? null, $sale['TraderStreetNo'] ?? null]), 60),
                    'city' => $this->cut($this->clean($sale['TraderCity'] ?? null), 60),
                    'postcode' => $this->cut($this->clean($sale['TraderZIP'] ?? null), 10),
                    // Epsilon stores the country as a Greek NAME («Ελλάδα»); the
                    // sample is all-Greek ΤΙΜ → GR. A non-GR sale would need an
                    // ISO map (follow-up); default GR for now.
                    'country' => 'GR',
                    'local_status' => 'active',
                    // myDATA cache columns (the denormalised latest-state cache).
                    'mydata_sent' => true,
                    'mydata_state' => $mark !== null ? 'VALID' : null,
                    'mydata_mark' => $mark,
                    'updated_at' => $now,
                ];

                // Raw query-builder writes (mirrors MigrateFromFirebird): bypass
                // the InvoiceLine recompute hook — which would OVERWRITE the
                // filed net/gross and throw on a zero-qty line, aborting the
                // batch — and the Invoice observers/activity-log. Stores exactly
                // what AADE has on file.
                $invoiceId = DB::table('invoices')
                    ->where('company_id', $this->companyId)->where('invcode', $invcode)->value('id');
                if ($invoiceId !== null) {
                    DB::table('invoices')->where('id', $invoiceId)->update($header);
                    $updated++;
                } else {
                    $invoiceId = DB::table('invoices')->insertGetId($header + [
                        'company_id' => $this->companyId,
                        'invcode' => $invcode,
                        'created_at' => $now,
                    ]);
                    $created++;
                }

                // Replace lines (verbatim filed values — no recompute hook).
                DB::table('invoice_lines')->where('invoice_id', $invoiceId)->delete();
                $lineRows = [];
                foreach (($sale['CommLines'] ?? []) as $line) {
                    $entity = $this->clean($line['EntityName'] ?? null);
                    $lineRows[] = [
                        'company_id' => $this->companyId,
                        'invoice_id' => $invoiceId,
                        'product_id' => $this->resolveProductByName($entity),
                        'qty' => round((float) ($line['Quantity'] ?? 1), 3),
                        'price_per_item' => round((float) ($line['Price'] ?? 0), 2),
                        'discount' => round((float) ($line['DiscLinePerc'] ?? 0), 4),
                        'vat_percent' => round((float) ($line['VATPercent'] ?? 0), 2),
                        'net_price' => round((float) ($line['NetVal'] ?? 0), 2),
                        'gross_price' => round((float) ($line['TotalVal'] ?? 0), 2),
                        'product_descr' => $entity ?? $this->clean($line['PrintingName'] ?? null) ?? '—',
                        'metric_unit' => $this->clean($line['MsntUnit'] ?? null),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($lineRows !== []) {
                    DB::table('invoice_lines')->insert($lineRows);
                }

                // Replace the myDATA audit mark. action='INSERT' — the codebase
                // finds the original filing via mydata_action='INSERT' (cancel /
                // credit-note correlation); 'SEND' would be invisible to them.
                DB::table('mydata_marks')->where('invoice_id', $invoiceId)->delete();
                if ($mark !== null) {
                    DB::table('mydata_marks')->insert([
                        'company_id' => $this->companyId,
                        'invoice_id' => $invoiceId,
                        'mark' => $mark,
                        'mydata_action' => 'INSERT',
                        'mark_date' => $issuedAt->toDateString(),
                        'mark_time' => $issuedAt->format('H:i:s'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                // These are historical, already-paid documents. Cash-term is
                // settled at issue by InvoiceBalance; credit-term needs a
                // settlement payment so it isn't a phantom open receivable.
                $this->settleImportedSale($invoiceId, $customerId, $paymentMethodId, $gross, $issuedAt);

                $counterByType[$typeId] = max($counterByType[$typeId] ?? 0, $docNum);
            }

            // Bump each touched type's counter so new ekdosi invoices continue
            // past the imported Epsilon numbers (next ΑΑ = max DocNum + 1).
            foreach ($counterByType as $typeId => $maxDocNum) {
                DB::table('invoice_types')->where('id', $typeId)->where('company_id', $this->companyId)
                    ->where('invcount', '<', $maxDocNum + 1)
                    ->update(['invcount' => $maxDocNum + 1]);
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Settle a just-imported historical sale. Cash-term (due_days = 0 or no
     * payment method) is already "paid at issue" by InvoiceBalance — we just
     * refresh the money cache. Credit-term gets a full settlement Payment dated
     * at issue (the PaymentObserver recomputes the cache), so a years-old paid
     * invoice doesn't surface as an open receivable on the dashboard/Καρτέλα.
     * Re-runnable: the prior import-payment is force-removed first.
     */
    private function settleImportedSale(int $invoiceId, ?int $customerId, ?int $paymentMethodId, float $gross, Carbon $issuedAt): void
    {
        Payment::query()->withoutGlobalScopes()
            ->where('invoice_id', $invoiceId)
            ->where('notes', self::IMPORT_PAYMENT_NOTE)
            ->forceDelete();

        $dueDays = $paymentMethodId !== null
            ? (int) (PaymentMethod::withoutGlobalScopes()->whereKey($paymentMethodId)->value('due_days') ?? 0)
            : 0;

        if ($dueDays > 0 && $customerId !== null && $gross > 0) {
            $payment = new Payment;
            $payment->forceFill([
                'company_id' => $this->companyId,
                'customer_id' => $customerId,
                'invoice_id' => $invoiceId,
                'payment_method_id' => $paymentMethodId,
                'pay_date' => $issuedAt->toDateString(),
                'amount' => $gross,
                'notes' => self::IMPORT_PAYMENT_NOTE,
            ])->save(); // PaymentObserver recomputes the money cache.

            return;
        }

        $invoice = Invoice::withoutGlobalScopes()->find($invoiceId);
        if ($invoice !== null) {
            app(InvoiceBalance::class)->recompute($invoice);
        }
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return array{created:int, updated:int, skipped:int}
     */
    public function importCustomers(array $rows): array
    {
        $created = $updated = $skipped = 0;

        DB::transaction(function () use ($rows, &$created, &$updated, &$skipped) {
            foreach ($rows as $row) {
                $afm = $this->clean($row['TIN'] ?? null);
                // Skip Epsilon's built-in generic retail placeholder (no real ΑΦΜ).
                if ($afm === null || $afm === '000000000') {
                    $skipped++;

                    continue;
                }

                $values = [
                    'name' => $this->clean($row['Name'] ?? '') ?? $afm,
                    'tax_office' => $this->clean($row['TaxOffice'] ?? null),
                    'occupation' => $this->clean($row['Profession'] ?? null),
                    'address1' => $this->join([$row['Street'] ?? null, $row['StreetNo'] ?? null]),
                    'city' => $this->clean($row['City'] ?? null),
                    'postcode' => $this->clean($row['PostalCode'] ?? null),
                    'country' => $this->clean($row['CountryISO2'] ?? null) ?: 'GR',
                    'email' => $this->clean($row['Email'] ?? null),
                    'phone1' => $this->clean($row['Phone1'] ?? null),
                    'phone2' => $this->clean($row['Phone2'] ?? null),
                    'discount' => (float) ($row['Discount'] ?? 0),
                    'details' => $this->clean($row['Remarks'] ?? null),
                    'payment_method_id' => $this->resolvePaymentMethod($this->clean($row['PaymentMethod'] ?? null)),
                ];

                $existing = Customer::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $this->companyId)
                    ->where('afm', $afm)
                    ->first();

                if ($existing !== null) {
                    // Refresh from source but never blank an operator-entered
                    // field the export lacks (overwrite non-null only); leave
                    // operator-managed flags (is_active, tags, …) untouched.
                    $existing->forceFill(array_filter($values, fn ($v) => $v !== null))->save();
                    $updated++;
                } else {
                    Customer::create($values + [
                        'company_id' => $this->companyId,
                        'afm' => $afm,
                        'is_active' => true,
                    ]);
                    $created++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Items + Services → products. Both shapes are identical (Name / VtclName /
     * MsntName / AccCategoryName / WhosalePrice / RetailPrice); services just
     * carry the «Υπηρεσία» accounting category.
     *
     * NATURAL KEY = Name (`description_short`): two Epsilon rows with the same
     * Name collapse onto one ekdosi product (a within-run repeat is warned).
     * An UNKNOWN VAT class is NOT guessed — the row is skipped + warned (never
     * silently billed at 24%).
     *
     * @param  array<int, array<string,mixed>>  $items
     * @param  array<int, array<string,mixed>>  $services
     * @return array{created:int, updated:int, skipped:int}
     */
    public function importProducts(array $items, array $services): array
    {
        $created = $updated = $skipped = 0;
        $rows = array_merge($items, $services);
        $seen = [];

        DB::transaction(function () use ($rows, &$created, &$updated, &$skipped, &$seen) {
            foreach ($rows as $row) {
                $name = $this->clean($row['Name'] ?? null);
                if ($name === null) {
                    $skipped++;

                    continue;
                }

                // Unknown VAT class → skip (don't guess a rate on a tax record).
                $vtcl = $this->clean($row['VtclName'] ?? null) ?? '';
                if (! array_key_exists($vtcl, self::VAT_CLASS_RATES)) {
                    $this->warn("Άγνωστη κλάση ΦΠΑ «{$vtcl}» — το είδος «{$name}» παραλείφθηκε.");
                    $skipped++;

                    continue;
                }
                $rate = self::VAT_CLASS_RATES[$vtcl];

                if (isset($seen[$name])) {
                    $this->warn("Διπλό όνομα είδους «{$name}» στο αρχείο — οι εγγραφές συγχωνεύονται σε ένα προϊόν.");
                }
                $seen[$name] = true;

                // WhosalePrice is the net wholesale price → ekdosi sell_price (net).
                $sell = round((float) ($row['WhosalePrice'] ?? 0), 2);

                $values = [
                    'product_category_id' => $this->resolveProductCategory($this->clean($row['AccCategoryName'] ?? null)),
                    'vat_category_id' => $this->resolveVatCategory($rate),
                    'metric_unit_id' => $this->resolveMetricUnit($this->clean($row['MsntName'] ?? null)),
                    'sell_price' => $sell,
                    'price_wvat' => round($sell * (1 + $rate / 100), 2),
                ];

                $existing = Product::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $this->companyId)
                    ->where('description_short', $name)
                    ->first();

                if ($existing !== null) {
                    // Refresh from source but never blank a field the source
                    // lacks (e.g. an operator-set unit) — overwrite non-null only.
                    $existing->forceFill(array_filter($values, fn ($v) => $v !== null))->save();
                    $updated++;
                } else {
                    Product::create($values + [
                        'company_id' => $this->companyId,
                        'description_short' => $name,
                        'is_active' => true,
                    ]);
                    $created++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /* ===================== resolution ===================== */

    private function resolveVatCategory(int $rate): int
    {
        if (isset($this->vatCache[$rate])) {
            return $this->vatCache[$rate];
        }
        $id = VatCategory::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('rate', $rate)
            ->value('id');

        if ($id === null) {
            $this->warn("Δεν υπάρχει κατηγορία ΦΠΑ {$rate}% — χρησιμοποιήθηκε η προεπιλεγμένη. Ρύθμισέ τη στο Setup → Vat Categories.");
            $id = $this->defaultVatCategory();
        }

        return $this->vatCache[$rate] = (int) $id;
    }

    private function defaultVatCategory(): int
    {
        if ($this->defaultVatId !== null) {
            return $this->defaultVatId;
        }
        $id = VatCategory::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->orderByDesc('is_default')
            ->orderByDesc('rate')
            ->value('id');

        if ($id === null) {
            throw new \RuntimeException('No VAT categories exist for this tenant — seed them first (Setup → Vat Categories → «Εισαγωγή τυπικών»).');
        }

        return $this->defaultVatId = (int) $id;
    }

    private function resolveMetricUnit(?string $epsilonName): ?int
    {
        if ($epsilonName === null) {
            return null;
        }
        $name = self::UNIT_MAP[$epsilonName] ?? $epsilonName;
        if (isset($this->unitCache[$name])) {
            return $this->unitCache[$name];
        }
        $id = MetricUnit::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('name', $name)
            ->value('id');
        if ($id === null) {
            $id = MetricUnit::create(['company_id' => $this->companyId, 'name' => $name])->getKey();
        }

        return $this->unitCache[$name] = (int) $id;
    }

    private function resolveProductCategory(?string $epsilonName): int
    {
        $name = $epsilonName !== null ? (self::CATEGORY_MAP[$epsilonName] ?? $epsilonName) : 'Λοιπά';
        if (isset($this->categoryCache[$name])) {
            return $this->categoryCache[$name];
        }
        $id = ProductCategory::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('description_short', $name)
            ->value('id');
        if ($id === null) {
            $id = ProductCategory::create([
                'company_id' => $this->companyId,
                'description_short' => $name,
                'markup' => 0,
            ])->getKey();
        }

        return $this->categoryCache[$name] = (int) $id;
    }

    private function resolvePaymentMethod(?string $description): ?int
    {
        if ($description === null) {
            return null;
        }
        if (array_key_exists($description, $this->paymentCache)) {
            return $this->paymentCache[$description];
        }
        $id = PaymentMethod::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('description', $description)
            ->value('id');

        return $this->paymentCache[$description] = $id !== null ? (int) $id : null;
    }

    private function resolveInvoiceType(string $code, string $name): int
    {
        if (isset($this->invoiceTypeCache[$code])) {
            return $this->invoiceTypeCache[$code];
        }
        $id = InvoiceType::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('code', $code)
            ->value('id');
        if ($id === null) {
            // Create a minimal type (no myDATA classification — the operator
            // completes it in Setup before re-filing anything of this series).
            $this->warn("Δημιουργήθηκε είδος παραστατικού «{$code}» — συμπλήρωσε την κατηγοριοποίηση myDATA στο Setup.");
            $id = InvoiceType::create([
                'company_id' => $this->companyId,
                'code' => $code,
                'name' => $name,
                'invcount' => 1,
                'show_on_menu' => true,
            ])->getKey();
        }

        return $this->invoiceTypeCache[$code] = (int) $id;
    }

    /** Resolve the sale's counterpart by ΑΦΜ; create a minimal customer if new. */
    private function resolveSaleCustomer(array $sale): ?int
    {
        $afm = $this->clean($sale['TraderTIN'] ?? null);
        if ($afm === null || $afm === '000000000') {
            return null;
        }
        if (isset($this->customerCache[$afm])) {
            return $this->customerCache[$afm];
        }
        $id = Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('afm', $afm)
            ->value('id');
        if ($id === null) {
            $this->warn("Δημιουργήθηκε πελάτης από πώληση (ΑΦΜ {$afm}) — εισήγαγε πρώτα τους πελάτες για πλήρη στοιχεία.");
            $id = Customer::create([
                'company_id' => $this->companyId,
                'afm' => $afm,
                'name' => $this->clean($sale['TraderName'] ?? null) ?? $afm,
                'address1' => $this->join([$sale['TraderStreet'] ?? null, $sale['TraderStreetNo'] ?? null]),
                'city' => $this->clean($sale['TraderCity'] ?? null),
                'postcode' => $this->clean($sale['TraderZIP'] ?? null),
                'country' => 'GR',
                'is_active' => true,
            ])->getKey();
        }

        return $this->customerCache[$afm] = (int) $id;
    }

    private function resolveProductByName(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }
        if (array_key_exists($name, $this->productByNameCache)) {
            return $this->productByNameCache[$name];
        }
        $id = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('description_short', $name)
            ->value('id');

        return $this->productByNameCache[$name] = $id !== null ? (int) $id : null;
    }

    /**
     * Epsilon date «29/05/2026» + optional time «29/05/2026 08:28:06» → Carbon.
     * createFromFormat THROWS on a mismatch (it never returns false), so each
     * attempt is guarded — a malformed value falls through to the next format,
     * then to now(), instead of aborting the whole import transaction. The
     * date-only fallback is pinned to startOfDay (createFromFormat would
     * otherwise inherit the current wall-clock time → a fabricated mark_time).
     */
    private function parseDateTime(?string $creationTime, ?string $date): Carbon
    {
        foreach ([['d/m/Y H:i:s', $creationTime, false], ['d/m/Y', $date, true]] as [$fmt, $value, $dateOnly]) {
            $value = $this->clean($value);
            if ($value !== null) {
                try {
                    $parsed = Carbon::createFromFormat($fmt, $value);

                    return $dateOnly ? $parsed->startOfDay() : $parsed;
                } catch (\Throwable) {
                    // fall through to the next format / now()
                }
            }
        }

        return Carbon::now();
    }

    /** Epsilon exports the MARK with a leading apostrophe («'4000…») — strip it. */
    private function cleanMark(mixed $value): ?string
    {
        $v = $this->clean($value);
        if ($v === null) {
            return null;
        }
        $v = ltrim($v, "'");

        return $v === '' ? null : $v;
    }

    /** Clamp a snapshot value to its column width (strict-mode safe). */
    private function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    /* ===================== helpers ===================== */

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);

        return $v === '' ? null : $v;
    }

    /** @param array<int, mixed> $parts */
    private function join(array $parts): ?string
    {
        $clean = array_filter(array_map(fn ($p) => $this->clean($p), $parts), fn ($p) => $p !== null);

        return $clean === [] ? null : implode(' ', $clean);
    }
}
