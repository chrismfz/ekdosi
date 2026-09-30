<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\RecomputeInvoiceTotals;
use App\Support\CustomerLanguage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reissue an invoice as a fresh DRAFT copy — new ΑΑ of the SAME invoice type,
 * same customer/lines/party snapshot, ready to fix and re-issue through the
 * normal lifecycle. The myDATA/credit/whmcs links are intentionally dropped and
 * the money caches are recomputed (never copied).
 *
 * Used standalone for «Επανέκδοση» (re-bill after a credit-cancelled original),
 * and as the second half of StornoAndReissue (credit + reissue in one click).
 * This action does NOT issue a credit note and does NOT submit anything.
 *
 * newSale() is the «Νέο από αυτό» twin: the same draft copy for a NEW, repeat
 * sale (same customer + lines, today). It does NOT replace the original — no
 * reissued_from / converted_from link (those drive the PROV-019 «the original is
 * still standing» warning and the informal→fiscal bookkeeping) — and the party
 * details come from the LIVE customer card (a new document states today's
 * details, not the old frozen snapshot). One copier, so the two can't drift.
 */
class ReissueInvoiceAsDraft
{
    public function __construct(
        private RecomputeInvoiceTotals $recompute,
    ) {}

    public function __invoke(Invoice $original): Invoice
    {
        return $this->copy($original, newSale: false);
    }

    /** «Νέο από αυτό»: a fresh draft for a repeat sale — see the class doc. */
    public function newSale(Invoice $original): Invoice
    {
        return $this->copy($original, newSale: true);
    }

    private function copy(Invoice $original, bool $newSale): Invoice
    {
        if ($original->credited_invoice_id !== null) {
            throw new RuntimeException($newSale
                ? 'Δεν γίνεται νέο παραστατικό από πιστωτικό — ανοίξτε το αρχικό τιμολόγιο.'
                : 'Δεν γίνεται επανέκδοση πιστωτικού τιμολογίου.');
        }

        $original->loadMissing(['lines', 'company', 'invoiceType', 'customer']);

        if ($original->invoiceType === null) {
            throw new RuntimeException('Το αρχικό παραστατικό δεν έχει τύπο — αδύνατη η επανέκδοση.');
        }

        // A new sale to an archived/deleted customer would silently bind the draft to
        // a card the form can't even show — make the operator decide.
        if ($newSale && $original->customer_id !== null && $original->customer === null) {
            throw new RuntimeException('Ο πελάτης του αρχικού έχει διαγραφεί/αρχειοθετηθεί — επαναφέρετέ τον ή φτιάξτε νέο παραστατικό.');
        }

        // Party: a reissue keeps the original's frozen snapshot (it corrects THAT
        // document); a new sale takes the live card — unless there is no card
        // (walk-in), where the snapshot is all we have.
        $customer = $newSale ? $original->customer : null;
        $party = $customer !== null ? [
            'company_name' => $customer->name,
            'vat_no' => $customer->afm,
            'vies_vat' => $customer->vat_vies,
            'occupation' => $customer->occupation,
            'address1' => $customer->address1,
            'address2' => $customer->address2,
            'city' => $customer->city,
            'postcode' => $customer->postcode,
            'country' => $customer->country ?: 'GR',
            // The customer's CURRENT explicit preference wins; else the original's.
            'language' => CustomerLanguage::stampForCustomer($customer) ?? $original->language,
        ] : [
            'company_name' => $original->company_name,
            'vat_no' => $original->vat_no,
            'vies_vat' => $original->vies_vat,
            'occupation' => $original->occupation,
            'address1' => $original->address1,
            'address2' => $original->address2,
            'city' => $original->city,
            'postcode' => $original->postcode,
            'country' => $original->country,
            // i18n: the reissue mirrors the original's frozen PDF language.
            'language' => $original->language,
        ];

        return DB::transaction(function () use ($original, $newSale, $party) {
            // Gapless-at-send: the reissued draft carries a provisional identity
            // (no ΑΑ); the real number is allocated at transmission.
            $reissue = Invoice::create([
                'company_id' => $original->company_id,
                'invoice_type_id' => $original->invoice_type_id,
                'customer_id' => $original->customer_id,
                'payment_method_id' => $original->payment_method_id,
                'bank_account_id' => $original->bank_account_id,
                'distribution_aim_id' => $original->distribution_aim_id,
                'delivery_method_id' => $original->delivery_method_id,
                // A new sale is delivered now, not on the old document's date.
                'delivery_date' => $newSale && $original->delivery_date !== null ? now()->toDateString() : $original->delivery_date,
                'issued_at' => now(),
                'local_status' => 'draft',
                // PROV-019: remember what this replaces, so filing it can soft-warn
                // while the reversed original is still standing at AADE.
                'reissued_from_invoice_id' => $newSale ? null : $original->id,
                // A reissued conversion is still the fiscal document of its informal
                // source («Ακύρωση & επανέκδοση») — keep the link, else the informal
                // would look unconverted (a 2nd conversion, a 2nd stock-out).
                'converted_from_invoice_id' => $newSale ? null : $original->converted_from_invoice_id,
                'header_discount_percent' => $original->header_discount_percent,
                // Taxes are recompute-owned (RecomputeInvoiceTaxes): carry the RATES +
                // categories; the amounts rebuild from the reissue's own net on save.
                // (Product-linked per-unit fees carry via the copied lines → products.)
                'withhold_rate' => $original->withhold_rate,
                'withhold_category' => $original->withhold_category,
                'fees_rate' => $original->fees_rate,
                'fees_category' => $original->fees_category,
                'other_taxes_rate' => $original->other_taxes_rate,
                'other_taxes_category' => $original->other_taxes_category,
                'stamp_duty_rate' => $original->stamp_duty_rate,
                'stamp_duty_category' => $original->stamp_duty_category,
                'deductions_rate' => $original->deductions_rate,
                'deductions_category' => $original->deductions_category,
                'notes' => $original->notes,
                'counterpart_branch' => $original->counterpart_branch,
            ] + $party + self::movementHeader($original));

            foreach ($original->lines as $line) {
                InvoiceLine::create([
                    'company_id' => $original->company_id,
                    'invoice_id' => $reissue->id,
                ] + $line->copyAttributes());
            }

            ($this->recompute)($reissue->fresh('lines'));

            return $reissue->refresh();
        });
    }

    /**
     * The delivery-note header of a combined ΤΔΑ / 9.3, so the copy is still a
     * movement document (without it a ΤΔΑ copy silently became a plain 1.1). The
     * lifecycle CACHE (delivery_state / transfer_mark / return_mark) is never copied
     * — it isn't fillable and belongs to the original's movement. Dispatch starts
     * «now», the create form's default; the operator adjusts it on the draft.
     *
     * @return array<string, mixed>
     */
    private static function movementHeader(Invoice $original): array
    {
        $header = $original->only([
            'is_delivery_note', 'without_digital_transport_tracking', 'move_purpose', 'other_move_purpose_title',
            'vehicle_number', 'loading_street', 'loading_number', 'loading_postcode', 'loading_city',
            'start_shipping_branch', 'delivery_street', 'delivery_number', 'delivery_postcode', 'delivery_city',
            'complete_shipping_branch', 'transport_type', 'carrier_afm', 'non_obligated_recipient',
        ]);
        // NOT NULL flags — never let a stray null reach the insert.
        foreach (['is_delivery_note', 'without_digital_transport_tracking', 'non_obligated_recipient'] as $flag) {
            $header[$flag] = (bool) $header[$flag];
        }
        $header['dispatch_at'] = $original->dispatch_at !== null ? now() : null;

        return $header;
    }
}
