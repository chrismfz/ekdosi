<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use App\Support\MyData\VatExemptionGuidance;
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
 * Lines are priced exactly like the invoice form (net `sell_price` + the product's
 * VAT rate), so the till shows the same total the receipt will have ({@see lineTotals}).
 * If issuing fails the sale stays a DRAFT invoice — nothing is lost; the error
 * carries its id so the operator can retry from «Παραστατικά».
 */
class CreatePosSale
{
    public function __construct(
        private readonly RecomputeInvoiceTotals $recompute,
        private readonly EInvoiceSubmitterFactory $submitters,
    ) {}

    /**
     * @param  list<array{product_id: int, qty: float|int|string, discount?: float|int|string|null}>  $items
     */
    public function __invoke(Company $company, array $items): Invoice
    {
        [$type, $method] = $this->settings($company);

        if ($items === []) {
            throw new RuntimeException('Το καλάθι είναι άδειο.');
        }

        $products = Product::query()->withoutGlobalScopes()
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
                'language' => 'el',
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
                $invoice->lines()->create([
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                    'product_descr' => $product->description_short,
                    'qty' => $qty,
                    'price_per_item' => (float) $product->sell_price,
                    'discount' => $discount,
                    'vat_percent' => $vat,
                    // A 0% line needs its §8.3 reason — suggested from the receipt type.
                    'vat_exemption_category' => $vat === 0.0 ? VatExemptionGuidance::recommendForType($type->mydata_type) : null,
                    'metric_unit' => $product->metricUnit?->name,
                ]);
            }

            return ($this->recompute)($invoice);
        });

        $this->issue($invoice->refresh());

        return $invoice->refresh();
    }

    /**
     * The till's money math, line by line — the SAME formulas InvoiceLine::saving
     * stores (net and gross rounded per line), so the screen and the receipt agree.
     *
     * @return array{net: float, gross: float}
     */
    public static function lineTotals(Product $product, float $qty, float $discount = 0.0): array
    {
        $net = round($qty * (float) $product->sell_price * (1 - $discount / 100), 2);

        return ['net' => $net, 'gross' => round($net * (1 + self::vatOf($product) / 100), 2)];
    }

    /** VAT rate a line of this product gets (the product's category rate). */
    public static function vatOf(Product $product): float
    {
        return (float) ($product->vatCategory?->rate ?? 0);
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

                return;
            }

            $this->submitters->for($invoice->company)->submit($invoice);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Η απόδειξη δεν εκδόθηκε: '.$e->getMessage().' — έμεινε πρόχειρο #'.$invoice->getKey().' (Παραστατικά).',
                previous: $e,
            );
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

        $type = InvoiceType::query()->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->whereKey($company->pos_invoice_type_id)
            ->first();
        if ($type === null || $type->mydata_type !== '11.1' || $type->is_credit) {
            throw new RuntimeException('Δεν έχει οριστεί σειρά αποδείξεων λιανικής (11.1) για το Ταμείο.');
        }

        $method = PaymentMethod::query()->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->whereKey($company->pos_payment_method_id)
            ->first();
        if ($method === null || (int) $method->due_days !== 0) {
            throw new RuntimeException('Δεν έχει οριστεί τρόπος πληρωμής «Μετρητά» (0 ημέρες) για το Ταμείο.');
        }

        return [$type, $method];
    }
}
