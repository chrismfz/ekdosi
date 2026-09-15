<?php

namespace App\Services\WhmcsInbox;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Support\Facades\DB;
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
        private readonly WhmcsInvoiceIngestor $ingestor,
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
            // Rate comes from the CHILD (a real taxed invoice), NOT the mass-pay
            // container — the container «has no VAT of its own» and can report
            // taxrate=0, which would file the whole thing at 0% ΦΠΑ.
            $childRate = $this->taxRateOf($child);
            // Net/gross PER LINE, honouring each line's own `taxed` flag — invoices
            // mix 24% and 0%/exempt lines, so a single-rate back-out would mis-bill.
            // The mass-pay's reference gross is the reconciliation ORACLE (via
            // MassPayChild::reconciles), NOT the value itself: computing gross from
            // the items also catches a wrong child rate OR a tenant whose `amount`s
            // are gross-inclusive — grossFromLines would then miss the reference and
            // reconcile() fails → hold, never a silent under/double-tax.
            $net = $this->netFromLines($lines);
            $gross = $this->grossFromLines($lines, $childRate);
            $childUser = $this->userIdOf($child);
            // A legal document: fold ONLY a child we can positively tie to the payer.
            // Unknown userid on either side → treat as third party (excluded), never
            // silently merged into the customer's invoice.
            $sameAsPayer = $massPayUser !== null && $childUser !== null && $childUser === $massPayUser;

            $model = new MassPayChild(
                whmcsInvoiceId: $childId,
                lines: $lines,
                net: $net,
                gross: $gross,
                referenceGross: $refGross,
                whmcsUserId: $childUser,
                sameParty: $sameAsPayer,
                payload: $child,
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

        // P0: never FOLD a child that already exists as an ISSUED or DRAFTED row on
        // its own — its lines are about to be merged into the consolidated invoice,
        // so folding one that's separately filed/drafted double-declares it (its own
        // ΑΑ+MARK AND again inside the consolidated). Refuse the whole consolidation.
        $childIds = array_map(fn (MassPayChild $c): int => $c->whmcsInvoiceId, $plan->sameParty);
        $alreadyIssued = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->whereIn('whmcs_invoice_id', $childIds)
            ->where(function ($q): void {
                $q->whereIn('status', [PendingWhmcsInvoice::STATUS_FILED, PendingWhmcsInvoice::STATUS_DRAFTED])
                    ->orWhereNotNull('invoice_id');
            })
            ->pluck('whmcs_invoice_id')
            ->all();
        if ($alreadyIssued !== []) {
            throw new RuntimeException(
                'Κάποια επιμέρους τιμολόγια έχουν ήδη εκδοθεί ή προσχεδιαστεί ξεχωριστά (#'
                .implode(', #', $alreadyIssued).') — δεν γίνεται ενοποίηση (θα διπλομετρούσε). '
                .'Εξέδωσε τα υπόλοιπα ξεχωριστά με «Ανάλυση σε επιμέρους».'
            );
        }

        $merged = $this->buildConsolidatedPayload($massPay->payload ?? [], $plan);

        return DB::transaction(function () use ($tenant, $massPay, $merged, $plan): array {
            $massPay->forceFill([
                'payload' => $merged,
                'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
                'hold_reason' => null,
            ])->save();

            // Tombstone each folded child (resolved, linked) so a later fetch can't
            // re-stage it as a stray invoice — the consolidated row now represents
            // it — and so the UI can group «τα τρία κάτω από αυτό». Never clobber a
            // child that was somehow already filed on its own.
            foreach ($plan->sameParty as $child) {
                $existing = PendingWhmcsInvoice::query()
                    ->where('company_id', $tenant->id)
                    ->where('whmcs_invoice_id', $child->whmcsInvoiceId)
                    ->lockForUpdate()
                    ->first();
                if ($existing?->status === PendingWhmcsInvoice::STATUS_FILED) {
                    continue;
                }
                PendingWhmcsInvoice::updateOrCreate(
                    ['company_id' => $tenant->id, 'whmcs_invoice_id' => $child->whmcsInvoiceId],
                    [
                        'source' => PendingWhmcsInvoice::SOURCE_WHMCS,
                        'whmcs_userid' => $child->whmcsUserId,
                        'customer_id' => $massPay->customer_id,
                        'masspay_parent_id' => $massPay->id,
                        'status' => PendingWhmcsInvoice::STATUS_RESOLVED,
                        'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
                        'payload' => $child->payload,
                        'notes' => 'Ενοποιήθηκε στο συγκεντρωτικό #'.$massPay->whmcs_invoice_id.'.',
                    ],
                );
            }

            return array_map(fn (MassPayChild $c): int => $c->whmcsInvoiceId, $plan->sameParty);
        });
    }

    /**
     * «Ανάλυση σε επιμέρους» — stage each child as its own pending row (its true
     * net/gross reconstructed) via the normal ingestor (customer match, third-party
     * routing, WH guards all reused), link it to the mass-pay, and resolve the
     * mass-pay «container». The operator then issues each child separately (each
     * auto-receipts its own WHMCS payment). Third-party children get the ingestor's
     * normal routing — nothing special here.
     *
     * @return array<int, int> the WHMCS child ids staged as their own rows
     */
    public function explode(Company $tenant, PendingWhmcsInvoice $massPay): array
    {
        $plan = $this->plan($tenant, $massPay);
        if (! $plan->isFullyFetched()) {
            throw new RuntimeException(
                'Δεν βρέθηκαν όλα τα τέκνα στο WHMCS (#'.implode(', #', $plan->missing).') — δοκίμασε ξανά.'
            );
        }
        if ($plan->fetched() === []) {
            throw new RuntimeException('Δεν βρέθηκαν τέκνα προς ανάλυση.');
        }

        $staged = [];
        foreach ($plan->fetched() as $child) {
            $corrected = $this->buildChildPayload($child);
            $this->ingestor->ingest($tenant, $corrected);

            $row = PendingWhmcsInvoice::query()
                ->where('company_id', $tenant->id)
                ->where('whmcs_invoice_id', $child->whmcsInvoiceId)
                ->first();
            // Never re-link an already-filed child (issued on its own earlier).
            if ($row !== null && $row->status !== PendingWhmcsInvoice::STATUS_FILED) {
                $row->forceFill(['masspay_parent_id' => $massPay->id])->save();
            }
            $staged[] = $child->whmcsInvoiceId;
        }

        $massPay->forceFill([
            'status' => PendingWhmcsInvoice::STATUS_RESOLVED,
            'hold_reason' => null,
            'notes' => 'Αναλύθηκε σε επιμέρους παραστατικά: #'.implode(', #', $staged).'.',
        ])->save();

        return $staged;
    }

    /**
     * One child's payload with its money breakdown reconstructed (WHMCS zeroed the
     * child's own `total`). Net/gross are already computed PER LINE at the child's
     * own rate in plan(); the child's own `taxrate` is preserved. Feeds the ingestor
     * so the child issues at its true value.
     *
     * @return array<string, mixed>
     */
    private function buildChildPayload(MassPayChild $child): array
    {
        $net = $child->net;
        $gross = $child->gross;

        $payload = $child->payload;
        $payload['invoiceid'] = $child->whmcsInvoiceId;
        $payload['items'] = ['item' => array_values($child->lines)];
        $payload['subtotal'] = number_format($net, 2, '.', '');
        $payload['tax'] = number_format(round($gross - $net, 2), 2, '.', '');
        // The child's own `taxrate` is preserved (do NOT overwrite from the container).
        $payload['total'] = number_format($gross, 2, '.', '');
        $payload['status'] = 'Paid';

        return $payload;
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
        // Rate from a CHILD (a real taxed invoice), never the mass-pay container
        // (which can report 0%). Same-party children share the rate; a reduced-rate
        // mix flattens to this (documented P2 — the mapper is single-rate anyway).
        $rate = $plan->sameParty === []
            ? 0.0
            : $this->taxRateOf($plan->sameParty[0]->payload);
        $items = [];
        $childIds = [];
        $childNotes = [];
        foreach ($plan->sameParty as $child) {
            $childIds[] = $child->whmcsInvoiceId;
            $childNotes[] = '#'.$child->whmcsInvoiceId.$this->childLabel($child);
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

        // Rich comment on the παραστατικό (PDF + admin): «Από συγκεντρωτικό #X —
        // εξοφλεί τα προτιμολόγια #a (…), #b (…), #c (…)». The mapper prefers this
        // over its default «Από προτιμολόγιο #…» when present.
        $container = (int) ($basePayload['invoiceid'] ?? $basePayload['id'] ?? 0);
        $merged['ekdosi_invoice_note'] = 'Από συγκεντρωτική πληρωμή WHMCS #'.$container
            .' — εξοφλεί τα προτιμολόγια '.implode(', ', $childNotes).'.';

        // Bookkeeping for the write-back (MARK → every source child).
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

    /** Σ of the line NET amounts (WHMCS line `amount` is tax-exclusive per line). */
    private function netFromLines(array $lines): float
    {
        $net = 0.0;
        foreach ($lines as $line) {
            $net += (float) ($line['amount'] ?? 0);
        }

        return round($net, 2);
    }

    /** A short «(Supermicro AMD Server…)» label from the child's first line, for the note. */
    private function childLabel(MassPayChild $child): string
    {
        $desc = trim((string) ($child->lines[0]['description'] ?? ''));
        if ($desc === '') {
            return '';
        }
        $short = mb_strlen($desc) > 40 ? mb_substr($desc, 0, 39).'…' : $desc;

        return ' ('.$short.')';
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
