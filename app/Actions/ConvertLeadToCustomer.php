<?php

namespace App\Actions;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Lead;
use App\Models\Quote;
use App\Support\Afm;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Μετατροπή σε πελάτη» — a Lead becomes (or is linked to) a Customer.
 *
 * Mirrors the lock → validate → create → link shape of ConvertQuoteToInvoice.
 * Two paths, one write:
 *   - NEW customer: copies the lead's identity fields 1:1 (same column names),
 *     carries `referred_by_customer_id`, copies the tags, turns
 *     `contact_person` into the primary CustomerContact.
 *   - LINK to an existing customer (the ΑΦΜ/email/phone dedupe match): nothing
 *     is created — never a duplicate customer for a legal party.
 * Then: quotes issued to the lead get their `customer_id`, the lead is stamped
 * `converted_customer_id` / `converted_at` / status=Won (the ONLY writer of
 * Won), and a `converted` row lands on the timeline. The lead's own notes /
 * attachments stay on the lead — the «before» history, reachable via the link.
 *
 * Idempotent: refuses if the lead is already converted, or if the target
 * customer already belongs to another lead (the column is unique).
 */
class ConvertLeadToCustomer
{
    public function __invoke(Lead $lead, ?Customer $existing = null): Customer
    {
        if ($lead->isConverted()) {
            throw new RuntimeException(
                'Το lead έχει ήδη μετατραπεί σε πελάτη (#'.$lead->converted_customer_id.').'
            );
        }

        if ($existing !== null && $existing->company_id !== $lead->company_id) {
            throw new RuntimeException('Ο πελάτης ανήκει σε άλλη εταιρεία.');
        }

        return DB::transaction(function () use ($lead, $existing): Customer {
            // Serialise conversions per tenant: two operators converting two
            // leads that share an ΑΦΜ must not both pass the owner check below
            // (the UNIQUE index would only surface the loser as a raw error).
            Company::query()->whereKey($lead->company_id)->lockForUpdate()->first();

            // Re-check under a row lock: a double-submit can't make two customers.
            $locked = Lead::query()->withoutGlobalScopes()->whereKey($lead->id)->lockForUpdate()->first();
            if ($locked === null || $locked->converted_customer_id !== null) {
                throw new RuntimeException('Το lead έχει ήδη μετατραπεί σε πελάτη.');
            }
            if ($locked->trashed()) {
                throw new RuntimeException('Το lead είναι διαγραμμένο — επανέφερέ το πρώτα.');
            }
            // «Μην ξαναενοχλήσετε» is a memory the dedupe relies on: converting
            // would erase it silently. The operator must change the status first
            // (a deliberate, logged step), then convert.
            if ($locked->status === LeadStatus::DoNotContact) {
                throw new RuntimeException('Το lead είναι «Μην ξαναενοχλήσετε» — άλλαξε πρώτα την κατάσταση (με λόγο) και μετά μετέτρεψέ το.');
            }

            // From here on work on the LOCKED row: the caller's instance may be
            // stale (another operator moved the status meanwhile) and the status
            // hook logs «from → won» from the instance it is saved through.
            $lead = $locked;

            if ($existing === null) {
                $this->assertNoLiveCustomerOwnsTheAfm($lead);
            }

            $customer = $existing ?? $this->createCustomer($lead);

            $alreadyLinked = Lead::query()
                ->withoutGlobalScopes()
                ->where('converted_customer_id', $customer->id)
                ->whereKeyNot($lead->id)
                ->first();
            if ($alreadyLinked !== null) {
                throw new RuntimeException(
                    'Ο πελάτης «'.$customer->name.'» είναι ήδη συνδεδεμένος με το lead #'.$alreadyLinked->id.'.'
                );
            }

            // Quotes issued to the lead now belong to the customer (snapshot
            // party fields stay as offered — the quote is history).
            Quote::query()
                ->withoutGlobalScopes()
                ->where('company_id', $lead->company_id)
                ->where('lead_id', $lead->id)
                ->whereNull('customer_id')
                ->update(['customer_id' => $customer->id]);

            // The ONLY place Won is written. The status hook appends the
            // «… → Πελάτης» row; the converted row below names the customer.
            $lead->update([
                'status' => LeadStatus::Won,
                'lost_reason' => null,
                'converted_customer_id' => $customer->id,
                'converted_at' => now(),
                'next_action_at' => null,
            ]);

            $lead->timeline()->create([
                'company_id' => $lead->company_id,
                'user_id' => auth()->id(),
                'type' => LeadActivityType::Converted->value,
                'happened_at' => now(),
                'body' => ($existing !== null ? 'Σύνδεση με υπάρχοντα πελάτη: ' : 'Νέος πελάτης: ').$customer->name,
                'meta' => ['customer_id' => $customer->id, 'linked_existing' => $existing !== null],
            ]);

            return $customer;
        });
    }

    /**
     * «Ποτέ διπλός πελάτης για ένα ΑΦΜ»: the modal only PRE-SELECTS «σύνδεση»;
     * the action is the authority. A live customer with the same ΑΦΜ means
     * the operator must link, not create. Read straight from the DB under the
     * transaction (never the request memo) and lock the owner row(s).
     */
    private function assertNoLiveCustomerOwnsTheAfm(Lead $lead): void
    {
        // The identity key (letters kept for a foreign VAT); a placeholder is
        // no identity → nothing to own, nothing to check.
        $afm = Afm::uniqueKey($lead->afm);
        if ($afm === null) {
            return;
        }

        // withTrashed: a soft-deleted owner could be restored later and become
        // the second live party — restore + link is the honest path. The
        // UNIQUE(company_id, afm_key) index is the last net behind this check.
        $owner = Customer::query()
            ->withTrashed()
            ->where('company_id', $lead->company_id)
            ->whereAfmKeyOf($afm)
            ->lockForUpdate()
            ->first();

        if ($owner !== null) {
            throw new RuntimeException($owner->trashed()
                ? 'Υπάρχει ΔΙΑΓΡΑΜΜΕΝΟΣ πελάτης με ΑΦΜ '.$afm.' («'.$owner->name.'») — επανέφερέ τον και διάλεξε «Σύνδεση με υπάρχοντα πελάτη».'
                : 'Υπάρχει ήδη πελάτης με ΑΦΜ '.$afm.' («'.$owner->name.'») — διάλεξε «Σύνδεση με υπάρχοντα πελάτη».');
        }
    }

    private function createCustomer(Lead $lead): Customer
    {
        $customer = Customer::create([
            'company_id' => $lead->company_id,
            'name' => $lead->name,
            'afm' => $lead->afm,
            'occupation' => $lead->occupation,
            'address1' => $lead->address1,
            'city' => $lead->city,
            'postcode' => $lead->postcode,
            'country' => $lead->country ?: 'GR',
            'phone1' => $lead->phone ?: $lead->mobile,
            'phone2' => ($lead->phone && $lead->mobile) ? $lead->mobile : null,
            'email' => $lead->email,
            'referred_by_customer_id' => $lead->referred_by_customer_id,
            'is_active' => true,
        ]);

        // Tags travel with the party (the lead keeps its own copy).
        $tagIds = $lead->tags()->pluck('tags.id')->all();
        if ($tagIds !== []) {
            $customer->tags()->syncWithoutDetaching($tagIds);
        }

        if (filled($lead->contact_person)) {
            CustomerContact::create([
                'company_id' => $lead->company_id,
                'customer_id' => $customer->id,
                'name' => $lead->contact_person,
                'phone' => $lead->mobile ?: $lead->phone,
                'email' => $lead->email,
                'is_primary' => true,
            ]);
        }

        return $customer;
    }
}
