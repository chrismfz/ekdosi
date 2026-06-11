<?php

namespace App\Services\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use App\Services\Whmcs\ContactCustomerResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * T-1c (timologia v2): the guided multi-party split.
 *
 * A single WHMCS invoice that mixes billing parties (some lines → contact A,
 * some → contact B, some → the reseller themselves) can't map to one ekdosi
 * counterpart. The legacy app did this by hand (relid_remover +
 * transfer_invoice). Here ekdosi proposes the grouping from the stored
 * resolve.php snapshot and, on operator confirmation, creates N per-party
 * ekdosi invoices.
 *
 * DELIBERATELY creates DRAFTS (local_status='draft'), NOT filed-at-AADE
 * documents. Filing N legally-significant documents in one batch invites the
 * partial-failure hazard (invoice 2 of 3 rejected mid-batch, ΑΑ counters half
 * consumed). Instead each draft is reviewed and filed individually through the
 * existing, proven per-invoice myDATA submit path. The split itself is local +
 * transactional (all-or-nothing), so it can't half-apply.
 *
 * Each draft carries invoices.whmcs_pending_id back to the pending row
 * (many-invoices ↔ one-pending-row); the pending row moves to status='split'.
 */
class WhmcsInvoiceSplitter
{
    public function __construct(
        private WhmcsInvoiceMapper $mapper,
        private InvoiceNumberer $numberer,
        private RecomputeInvoiceTotals $recompute,
        private ContactCustomerResolver $contactResolver,
    ) {}

    /**
     * Build the per-party plan from the stored resolution — used by BOTH the
     * preview and the execute path so what the operator sees is what gets
     * created.
     *
     * @return array<int, array{
     *   key: string, label: string, is_receipt: bool, item_ids: array<int,int>,
     *   contact: ?array<string,mixed>, customer: ?Customer
     * }>
     */
    public function planGroups(Company $tenant, PendingWhmcsInvoice $pending): array
    {
        $lines = $pending->third_party_resolution['lines'] ?? [];
        if (! is_array($lines)) {
            return [];
        }

        /** @var array<string, array<string,mixed>> $groups */
        $groups = [];
        foreach ($lines as $line) {
            $routed = ! empty($line['routed']) && ! empty($line['contact']);
            if ($routed) {
                $contactId = (int) ($line['contact']['id'] ?? 0);
                $key = 'contact:'.$contactId;
                $label = (string) ($line['contact']['company_name'] ?? ('Επαφή #'.$contactId));
                $contact = $line['contact'];
            } else {
                $key = 'reseller';
                $label = (string) ($pending->customer?->name ?? 'Πελάτης WHMCS');
                $contact = null;
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $label,
                    // Routed (third-party) group: the explicit per-route flag.
                    // Own/reseller group: the PRIMARY customer's intent (ΑΦΜ +
                    // wantsinvoice) — NOT the resolution's is_receipt, which the
                    // plugin defaults to false for own lines (so a no-ΑΦΜ retail
                    // customer's own portion would wrongly become a τιμολόγιο).
                    'is_receipt' => $routed
                        ? (bool) ($line['is_receipt'] ?? false)
                        : $pending->ownLinesAreReceipt(),
                    'item_ids' => [],
                    'contact' => $contact,
                ];
            }
            $itemId = (int) ($line['item_id'] ?? 0);
            if ($itemId > 0) {
                $groups[$key]['item_ids'][] = $itemId;
            }
        }

        // Resolve each group's ekdosi Customer: contacts via the contact
        // resolver (find-or-create by ΑΦΜ); the reseller group is the pending
        // row's matched customer.
        $out = [];
        foreach ($groups as $group) {
            $group['customer'] = $group['contact'] !== null
                ? $this->contactResolver->resolve($tenant, $group['contact'])
                : $pending->customer;
            $out[] = $group;
        }

        return $out;
    }

    /**
     * Execute the split: create one draft invoice per resolvable party group.
     * All-or-nothing (single transaction). Refuses if any group can't resolve
     * to a customer — so we never produce a partial split.
     *
     * @return array<int, Invoice> The created draft invoices.
     */
    public function split(
        Company $tenant,
        PendingWhmcsInvoice $pending,
        InvoiceType $invoiceType,
        ?InvoiceType $receiptType = null,
        ?int $splitByUserId = null,
    ): array {
        if ($pending->company_id !== $tenant->id) {
            throw new RuntimeException('Pending row belongs to a different tenant.');
        }
        if ($pending->third_party_state !== PendingWhmcsInvoice::TP_MULTI) {
            throw new RuntimeException('Μόνο πολυμερή (multi-party) τιμολόγια χρειάζονται διαχωρισμό.');
        }
        if ($invoiceType->company_id !== $tenant->id) {
            throw new RuntimeException('Invoice type belongs to a different tenant.');
        }
        if ($receiptType !== null && $receiptType->company_id !== $tenant->id) {
            throw new RuntimeException('Receipt type belongs to a different tenant.');
        }

        $groups = $this->planGroups($tenant, $pending);
        if (count($groups) < 2) {
            throw new RuntimeException('Ο διαχωρισμός χρειάζεται τουλάχιστον δύο δικαιούχους.');
        }

        // Validate up-front, so the transaction below can't half-apply:
        //  - every group must resolve to a customer (e.g. contact missing ΑΦΜ);
        //  - a group flagged απόδειξη (is_receipt) needs a receipt type to file
        //    under — otherwise we'd silently issue a receipt routing as an
        //    invoice (the legacy τιμολόγιο/απόδειξη distinction).
        foreach ($groups as $group) {
            if (! $group['customer'] instanceof Customer) {
                throw new RuntimeException(sprintf(
                    'Ο δικαιούχος «%s» δεν αντιστοιχεί σε πελάτη ekdosi (λείπει ΑΦΜ ή σύνδεση). '
                    .'Διόρθωσέ τον πριν τον διαχωρισμό.',
                    $group['label'],
                ));
            }
            if ($group['is_receipt'] && $receiptType === null) {
                throw new RuntimeException(sprintf(
                    'Ο δικαιούχος «%s» χρειάζεται απόδειξη — επίλεξε τύπο απόδειξης πριν τον διαχωρισμό.',
                    $group['label'],
                ));
            }
        }

        return DB::transaction(function () use ($tenant, $pending, $invoiceType, $receiptType, $groups, $splitByUserId) {
            $locked = PendingWhmcsInvoice::query()
                ->whereKey($pending->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, [PendingWhmcsInvoice::STATUS_FILED, PendingWhmcsInvoice::STATUS_SPLIT], true)) {
                throw new RuntimeException(
                    'Το WHMCS #'.$locked->whmcs_invoice_id.' έχει ήδη '
                    .($locked->status === PendingWhmcsInvoice::STATUS_FILED ? 'καταχωρηθεί' : 'διαχωριστεί').'.'
                );
            }

            $created = [];
            foreach ($groups as $group) {
                /** @var Customer $customer */
                $customer = $group['customer'];
                // απόδειξη groups file under the receipt type; everything else
                // under the invoice type (validated above that a receipt type
                // exists when needed).
                $groupType = $group['is_receipt'] ? $receiptType : $invoiceType;
                $mapped = $this->mapper->map($tenant, $locked, $customer, $groupType, $group['item_ids']);

                // A group with items that all map away (e.g. every line blank)
                // would silently vanish from billing — refuse rather than
                // under-bill. (item_ids is non-empty by construction here.)
                if ($mapped['lines'] === []) {
                    throw new RuntimeException(sprintf(
                        'Ο δικαιούχος «%s» δεν παρήγαγε καμία γραμμή (κενές περιγραφές;) — '
                        .'έλεγξε το WHMCS τιμολόγιο πριν τον διαχωρισμό.',
                        $group['label'],
                    ));
                }

                $allocation = $this->numberer->allocate($tenant, $groupType->code);

                $header = $mapped['header'];
                $header['code'] = $allocation->code;
                $header['invcode'] = $allocation->invcode;
                $header['whmcs_pending_id'] = $locked->id;
                $header['local_status'] = 'draft';

                $invoice = Invoice::create($header);
                foreach ($mapped['lines'] as $lineData) {
                    InvoiceLine::create(array_merge($lineData, [
                        'company_id' => $tenant->id,
                        'invoice_id' => $invoice->id,
                    ]));
                }
                ($this->recompute)($invoice->fresh('lines'));
                $created[] = $invoice;
            }

            if ($created === []) {
                throw new RuntimeException('Δεν δημιουργήθηκε κανένα παραστατικό από τον διαχωρισμό.');
            }

            $codes = implode(', ', array_map(static fn (Invoice $i) => $i->invcode, $created));
            $locked->update([
                'status' => PendingWhmcsInvoice::STATUS_SPLIT,
                'filed_by_user_id' => $splitByUserId,
                'notes' => 'Διαχωρίστηκε σε '.count($created).' προσχέδια παραστατικά: '.$codes
                    .'. Καταχώρησε το καθένα ξεχωριστά στην ΑΑΔΕ.',
            ]);

            return $created;
        });
    }
}
