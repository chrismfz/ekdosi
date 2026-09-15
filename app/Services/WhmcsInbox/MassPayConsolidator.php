<?php

namespace App\Services\WhmcsInbox;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use RuntimeException;

/**
 * Resolves a WHMCS mass-pay «container» (an invoice whose lines only reference
 * OTHER invoices — no sale, no VAT of its own; #32310 → #32280/#32263/#32256).
 *
 * The customer made ONE payment for N proformas. The operator chooses:
 *   - CONSOLIDATE → one παραστατικό with all the children's real service lines
 *     (νόμιμο συγκεντρωτικό, ΕΛΠ ν.4308/2014 άρθρο 6), or
 *   - EXPLODE → each child as its own παραστατικό (per-order).
 *
 * Either way the mass-pay itself is NEVER filed. WHMCS zeroes a child's own
 * `total` when it rolls into the mass-pay, so the truth is the child's line
 * items — we fetch each child fresh (GetInvoice) and reconstruct a consistent
 * net/gross breakdown, anchored to the mass-pay's per-child reference gross.
 */
class MassPayConsolidator
{
    public function __construct(
        private readonly WhmcsInvoiceFetcher $fetcher,
    ) {}

    /**
     * Fetch every child, extract its real service lines, and partition by billing
     * party (same as the mass-pay's customer vs a third party). Read-only — no writes.
     */
    public function plan(Company $tenant, PendingWhmcsInvoice $massPay): MassPayConsolidation
    {
        if (! $massPay->isConsolidatedPayment()) {
            throw new RuntimeException('Το παραστατικό δεν είναι συγκεντρωτικό WHMCS (mass-pay).');
        }

        $fetch = $this->fetcher->for($tenant);
        if ($fetch === null) {
            throw new RuntimeException('Δεν έχει ρυθμιστεί WHMCS για αυτόν τον πελάτη — δεν μπορώ να φέρω τα τέκνα.');
        }

        $payload = $massPay->payload ?? [];
        $rate = $this->taxRateOf($payload);
        $referenceGross = $this->referenceGrossByChild($payload);
        $massPayUser = $this->userIdOf($payload);

        $sameParty = [];
        $thirdParty = [];
        $missing = [];

        foreach ($massPay->consolidatedPaymentRefs() as $childId) {
            $child = $fetch($childId);
            if ($child === null) {
                $missing[] = $childId;

                continue;
            }

            $lines = $this->serviceLines($child);
            $refGross = $referenceGross[$childId] ?? 0.0;
            // The child's own gross reconstructed from the reference (its `total`
            // is zeroed in WHMCS); net backs out of the tenant/invoice rate.
            $gross = $refGross > 0.0 ? $refGross : $this->grossFromLines($lines, $rate);
            $net = round($gross / (1 + $rate / 100), 2);
            $childUser = $this->userIdOf($child);
            $sameAsPayer = $massPayUser === null || $childUser === null || $childUser === $massPayUser;

            $model = new MassPayChild(
                whmcsInvoiceId: $childId,
                lines: $lines,
                net: $net,
                gross: $gross,
                referenceGross: $refGross,
                whmcsUserId: $childUser,
                sameParty: $sameAsPayer,
            );

            $sameAsPayer ? ($sameParty[] = $model) : ($thirdParty[] = $model);
        }

        return new MassPayConsolidation(
            sameParty: $sameParty,
            thirdParty: $thirdParty,
            missing: $missing,
            massPayTotal: $this->grossTotalOf($payload),
        );
    }

    /**
     * «Ενοποίηση» — rewrite the mass-pay row IN PLACE into a single consolidated
     * draft-source: its payload becomes the union of the same-party children's
     * real service lines (with a reconstructed net/gross breakdown), and it goes
     * back to pending_review so the operator issues ONE παραστατικό. The mass-pay
     * is never filed as-is; the source pointer payload is kept for audit. Refuses
     * (points to «Ανάλυση σε επιμέρους») when a child is a third party or missing,
     * or when a child's items don't reconcile to the payment.
     *
     * @return array<int, int> the WHMCS child ids folded into the consolidated invoice
     */
    public function consolidate(Company $tenant, PendingWhmcsInvoice $massPay): array
    {
        $plan = $this->plan($tenant, $massPay);

        if (! $plan->isFullyFetched()) {
            throw new RuntimeException(
                'Δεν βρέθηκαν όλα τα τέκνα στο WHMCS (#'.implode(', #', $plan->missing).') — δοκίμασε ξανά.'
            );
        }
        if ($plan->hasThirdParty()) {
            throw new RuntimeException(
                'Το bundle περιέχει παραστατικό τρίτου πελάτη — χρησιμοποίησε «Ανάλυση σε επιμέρους» '
                .'ώστε το κάθε τέκνο (και το τρίτου) να βγει ξεχωριστά.'
            );
        }
        if ($plan->sameParty === []) {
            throw new RuntimeException('Δεν βρέθηκαν γραμμές προς ενοποίηση.');
        }
        foreach ($plan->sameParty as $child) {
            if (! $child->reconciles()) {
                throw new RuntimeException(
                    'Το τέκνο #'.$child->whmcsInvoiceId.' δεν συμφωνεί με το ποσό της μαζικής πληρωμής '
                    .'(γραμμές '.number_format($child->gross, 2).' € vs '.number_format($child->referenceGross, 2)
                    .' €) — έλεγξέ το χειροκίνητα.'
                );
            }
        }

        $merged = $this->buildConsolidatedPayload($massPay->payload ?? [], $plan);

        $massPay->forceFill([
            'payload' => $merged,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'hold_reason' => null,
        ])->save();

        return array_map(fn (MassPayChild $c): int => $c->whmcsInvoiceId, $plan->sameParty);
    }

    /**
     * Build the merged WHMCS-shaped payload for the same-party children — the
     * consolidated παραστατικό's source. Items are the union of the children's
     * real service lines; the breakdown (subtotal/tax/total/taxrate) is
     * reconstructed so WhmcsInvoiceMapper's net/gross detection is unambiguous.
     *
     * @return array<string, mixed>
     */
    public function buildConsolidatedPayload(array $basePayload, MassPayConsolidation $plan): array
    {
        $rate = $this->taxRateOf($basePayload);
        $items = [];
        $childIds = [];
        foreach ($plan->sameParty as $child) {
            $childIds[] = $child->whmcsInvoiceId;
            foreach ($child->lines as $line) {
                $items[] = $line;
            }
        }

        $net = $plan->samePartyNet();
        $gross = $plan->samePartyGross();

        // Carry the customer identity + gateway from the mass-pay; overwrite the
        // money breakdown with the reconstructed net/gross so the mapper reads the
        // line `amount`s as NET (Σ items == subtotal), not gross.
        $merged = $basePayload;
        $merged['items'] = ['item' => array_values($items)];
        $merged['subtotal'] = number_format($net, 2, '.', '');
        $merged['tax'] = number_format(round($gross - $net, 2), 2, '.', '');
        $merged['taxrate'] = number_format($rate, 3, '.', '');
        $merged['total'] = number_format($gross, 2, '.', '');
        $merged['status'] = 'Paid';

        // Bookkeeping for the write-back (MARK → every source child) + a note.
        $merged['ekdosi_consolidated_children'] = array_values($childIds);
        // Keep the original mass-pay pointer payload for audit (it's overwritten).
        $merged['ekdosi_masspay_source'] = [
            'items' => $basePayload['items'] ?? null,
            'total' => $basePayload['total'] ?? null,
        ];

        return $merged;
    }

    // --- payload readers -----------------------------------------------------

    /** @param array<string,mixed> $payload */
    private function serviceLines(array $payload): array
    {
        $items = $payload['items']['item'] ?? null;
        if (! is_array($items) || $items === []) {
            return [];
        }
        if (! array_is_list($items)) {
            $items = [$items];
        }

        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item) || trim((string) ($item['description'] ?? '')) === '') {
                continue;
            }
            // A child should carry real product lines; skip any stray reference
            // line (defensive — a child of a mass-pay is not itself a mass-pay).
            if (strtolower(trim((string) ($item['type'] ?? ''))) === 'invoice') {
                continue;
            }
            $lines[] = $item;
        }

        return $lines;
    }

    /** @param array<string,mixed> $payload @return array<int, float> childId => gross */
    private function referenceGrossByChild(array $payload): array
    {
        $items = $payload['items']['item'] ?? [];
        if (! array_is_list($items)) {
            $items = [$items];
        }
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $relid = (int) ($item['relid'] ?? 0);
            if ($relid > 0) {
                $out[$relid] = round((float) ($item['amount'] ?? 0), 2);
            }
        }

        return $out;
    }

    private function grossFromLines(array $lines, float $rate): float
    {
        $gross = 0.0;
        foreach ($lines as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            $taxed = (int) ($line['taxed'] ?? 0) === 1;
            $gross += $taxed ? $amount * (1 + $rate / 100) : $amount;
        }

        return round($gross, 2);
    }

    /** @param array<string,mixed> $payload */
    private function taxRateOf(array $payload): float
    {
        $rate = (float) ($payload['taxrate'] ?? 0);

        return $rate > 0 ? $rate : 0.0;
    }

    /** @param array<string,mixed> $payload */
    private function grossTotalOf(array $payload): float
    {
        return round((float) ($payload['total'] ?? 0), 2);
    }

    /** @param array<string,mixed> $payload */
    private function userIdOf(array $payload): ?int
    {
        $id = (int) ($payload['userid'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
