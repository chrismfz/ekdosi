<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use App\Support\LineMoney;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A till sale (docs/woocommerce-bridge-plan.md §11): cart → retail receipt
 * (11.1 ΑΛΠ) ISSUED on the spot, through the same pipeline as any invoice.
 *
 * 1. Create a DRAFT on the company's POS series, no customer (anonymous retail —
 *    a 11.x files without a counterpart), cash payment method (due_days 0 →
 *    settled at issue by InvoiceBalance, no Payment row needed).
 * 2. Issue it: a filing tenant → the e-invoice submitter (assigns the ΑΑ, files,
 *    sets MARK/QR and flips it active — which moves the stock); a non-filing
 *    tenant → the same atomic number + activate as «Οριστικοποίηση».
 *
 * Lines are SHELF-priced (POS-2, gross-anchored): the tag price is charged exactly
 * and the till shows the same total the receipt will have ({@see lineTotals}).
 * If issuing fails the sale stays a DRAFT invoice — nothing is lost; the
 * PosSaleNotIssued carries its id so the operator retries THAT draft from
 * «Παραστατικά» (never a new ring-up of the same cart).
 */
class CreatePosSale
{
    public function __construct(
        private readonly RecomputeInvoiceTotals $recompute,
        private readonly EInvoiceSubmitterFactory $submitters,
    ) {}

    /**
     * `price` = the GROSS unit price the cashier typed — honoured ONLY for a product
     * flagged «ελεύθερη τιμή» (pos_open_price); every other line sells at its
     * catalogue price whatever the cart says.
     *
     * @param  list<array{product_id: int, qty: float|int|string, discount?: float|int|string|null, price?: float|int|string|null}>  $items
     */
    public function __invoke(Company $company, array $items): Invoice
    {
        [$type, $method] = $this->settings($company);

        if ($items === []) {
            throw new RuntimeException('Το καλάθι είναι άδειο.');
        }

        $products = Product::query()->withoutGlobalScope(CompanyScope::class)
            ->with(['vatCategory', 'metricUnit'])
            ->where('company_id', $company->getKey())
            ->whereKey(array_map(fn (array $i) => (int) $i['product_id'], $items))
            ->sellable()
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $invoice = DB::transaction(function () use ($company, $items, $products, $type, $method): Invoice {
            $invoice = Invoice::create([
                'company_id' => $company->getKey(),
                'invoice_type_id' => $type->getKey(),
                'customer_id' => null,
                'issued_at' => now(),
                'local_status' => 'draft',
                'header_discount_percent' => 0,
                'payment_method_id' => $method->getKey(),
                'country' => $company->country_code ?: 'GR',
                'language' => null,   // auto = from the frozen country, as any no-choice document
            ]);

            foreach ($items as $item) {
                $product = $products->get((int) $item['product_id']);
                if ($product === null) {
                    throw new RuntimeException('Το είδος #'.(int) $item['product_id'].' δεν πουλιέται (ανενεργό, γονικό με παραλλαγές ή άλλης εταιρείας).');
                }
                $qty = (float) $item['qty'];
                $discount = (float) ($item['discount'] ?? 0);
                if ($qty <= 0 || $discount < 0 || $discount > 100) {
                    throw new RuntimeException('Μη έγκυρη ποσότητα/έκπτωση για «'.$product->description_short.'».');
                }

                $vat = self::vatOf($product);
                if ($vat === null) {
                    // No live category (none, or a retired/deleted one): never guess a
                    // rate on a legal receipt — the operator re-points the product.
                    throw new RuntimeException('Το «'.$product->description_short.'» δεν έχει ενεργή κατηγορία ΦΠΑ — όρισέ τη στο είδος.');
                }
                $unitGross = self::unitGross($product, $item['price'] ?? null);
                if ($unitGross === null && $product->isOpenPrice()) {
                    throw new RuntimeException('Το «'.$product->description_short.'» είναι ελεύθερης τιμής — γράψε την τιμή του.');
                }
                $invoice->lines()->create([
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                    'product_descr' => $product->description_short,
                    'qty' => $qty,
                    // POS-2: a product with a (valid) shelf price is sold at it exactly —
                    // gross-anchored (price_per_item becomes its net mirror). Without one, the
                    // line is net-priced exactly like the invoice form prices it.
                    'gross_unit_price' => $unitGross,
                    'price_per_item' => (float) $product->sell_price,
                    'discount' => $discount,
                    'vat_percent' => $vat,
                    // A 0% line carries its §8.3 reason from the product's VAT category
                    // (null → the tenant's single 0% reason, as on any invoice).
                    'vat_exemption_category' => $vat == 0.0 ? $product->vatCategory?->vat_exemption_category : null,
                    'metric_unit' => $product->metricUnit?->name,
                ]);
            }

            $invoice = ($this->recompute)($invoice);
            // A 0,00 € receipt (0-priced item, 100% discount) is not a sale.
            if ((float) $invoice->payableTotal() <= 0) {
                throw new RuntimeException('Η απόδειξη βγαίνει 0,00 € — έλεγξε τιμές/εκπτώσεις.');
            }

            return $invoice;
        });

        $this->issue($invoice->refresh());

        return $invoice->refresh();
    }

    /**
     * The till's money math, line by line — the SAME formula InvoiceLine::saving
     * stores for a shelf-priced (gross-anchored) line, so the screen and the receipt
     * agree to the cent. `levy` = the product-linked tax/fee of the line
     * (RecomputeInvoiceTaxes: qty × per-unit, e.g. the plastic bag), signed (a
     * deduction is negative).
     *
     * @return array{net: float, gross: float, levy: float}
     */
    public static function lineTotals(Product $product, float $qty, float $discount = 0.0, float|int|string|null $price = null): array
    {
        $unitGross = self::unitGross($product, $price);
        $rate = self::vatOf($product) ?? 0.0;
        $money = $unitGross !== null || $product->isOpenPrice()
            ? LineMoney::fromGross($qty, $unitGross ?? 0.0, $discount, $rate)
            : LineMoney::fromNet($qty, (float) $product->sell_price, $discount, $rate);
        $levy = 0.0;
        $perUnit = (float) ($product->mydata_tax_per_unit ?? 0);
        $taxType = (int) ($product->mydata_tax_type ?? 0);
        if ($perUnit > 0 && in_array($taxType, [2, 3, 4, 5], true)) {
            $levy = ($taxType === 5 ? -1 : 1) * $qty * $perUnit;
        }

        return ['net' => $money['net'], 'gross' => $money['gross'], 'levy' => $levy];
    }

    /**
     * The VAT-inclusive SHELF price a till line is sold at (POS-2), i.e. its anchor:
     * the product's valid shelf price (Product::shelfGross — the price on the tag),
     * or for an open-price product the price the cashier TYPED.
     * Null = no anchor: a catalogue product without a valid shelf price is NET-priced
     * (exactly as the invoice form prices it); an open-price product without a valid
     * typed price is refused.
     */
    public static function unitGross(Product $product, float|int|string|null $price): ?float
    {
        if (! $product->isOpenPrice()) {
            return $product->shelfGross(self::vatOf($product) ?? 0.0);
        }

        $gross = is_numeric($price) ? (float) $price : 0.0;
        if ($gross <= 0 || $gross > 1_000_000) {
            return null;
        }

        return round($gross, 2);
    }

    /** VAT rate a line of this product gets (its category's rate); null = none set. */
    public static function vatOf(Product $product): ?float
    {
        $rate = $product->vatCategory?->rate;

        return $rate === null ? null : (float) $rate;
    }

    /**
     * Issue the draft: file it (filing tenant) or number + activate it (non-filing).
     */
    private function issue(Invoice $invoice): void
    {
        try {
            if ($invoice->isIssuedAtFinalize()) {
                DB::transaction(function () use ($invoice): void {
                    if ($invoice->code === null) {
                        app(InvoiceNumberer::class)->assign($invoice);
                    }
                    $invoice->update(['local_status' => 'active']);
                });
            } else {
                $this->submitters->for($invoice->company)->submit($invoice);
            }
        } catch (\Throwable $e) {
            // A step AFTER the filing committed (auto-email queueing…) may throw on
            // an invoice that IS issued — that's a sale, not a failure.
            if ($invoice->refresh()->local_status === 'active') {
                report($e);

                return;
            }
            throw new PosSaleNotIssued((int) $invoice->getKey(), $e);
        }

        // «Nothing threw» isn't «issued»: a submitter that returned without
        // promoting the draft must not print as a sale.
        if ($invoice->refresh()->local_status !== 'active') {
            throw new PosSaleNotIssued((int) $invoice->getKey(), new RuntimeException('το παραστατικό δεν οριστικοποιήθηκε'));
        }
    }

    /**
     * @return array{0: InvoiceType, 1: PaymentMethod}
     */
    private function settings(Company $company): array
    {
        if (! $company->hasPos()) {
            throw new RuntimeException('Το «Ταμείο» δεν είναι ενεργό για αυτή την εταιρεία.');
        }

        $type = InvoiceType::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereKey($company->pos_invoice_type_id)
            ->first();
        if ($type === null || $type->mydata_type !== '11.1' || $type->is_credit) {
            throw new RuntimeException('Δεν έχει οριστεί σειρά αποδείξεων λιανικής (11.1) για το Ταμείο.');
        }

        $method = PaymentMethod::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereKey($company->pos_payment_method_id)
            ->first();
        if ($method === null || (int) $method->due_days !== 0
            || ($method->mydata_payment_type !== null && (int) $method->mydata_payment_type !== 3)) {
            throw new RuntimeException('Δεν έχει οριστεί τρόπος πληρωμής «Μετρητά» (0 ημέρες) για το Ταμείο.');
        }

        return [$type, $method];
    }
}
