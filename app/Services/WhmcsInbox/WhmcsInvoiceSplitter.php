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
                    'is_receipt' => (bool) ($line['is_receipt'] ?? false),
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

        $groups = $this->planGroups($tenant, $pending);
        if (count($groups) < 2) {
            throw new RuntimeException('Ο διαχωρισμός χρειάζεται τουλάχιστον δύο δικαιούχους.');
        }

        // Validate up-front: every group must resolve to a customer, so the
        // transaction below can't half-apply (e.g. contact missing ΑΦΜ).
        foreach ($groups as $group) {
            if (! $group['customer'] instanceof Customer) {
                throw new RuntimeException(sprintf(
                    'Ο δικαιούχος «%s» δεν αντιστοιχεί σε πελάτη ekdosi (λείπει ΑΦΜ ή σύνδεση). '
                    .'Διόρθωσέ τον πριν τον διαχωρισμό.',
                    $group['label'],
                ));
            }
        }

        return DB::transaction(function () use ($tenant, $pending, $invoiceType, $groups, $splitByUserId) {
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
                $mapped = $this->mapper->map($tenant, $locked, $customer, $invoiceType, $group['item_ids']);

                // A group whose items all mapped away (e.g. blank descriptions)
                // yields no lines — skip rather than create an empty invoice.
                if ($mapped['lines'] === []) {
                    continue;
                }

                $allocation = $this->numberer->allocate($tenant, $invoiceType->code);

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
