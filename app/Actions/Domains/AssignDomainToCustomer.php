<?php

namespace App\Actions\Domains;

use App\Enums\BillingCycle;
use App\Enums\DomainStatus;
use App\Enums\ServiceContractStatus;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\ServiceContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Ανάθεση σε πελάτη» (Πυλώνας A / A1b) — the null→customer transition of an
 * unassigned («αδέσποτο») domain, docs/domains/README.md §8.2/§9. Creates the
 * domain's OWN ServiceContract (1:1 — owner decision) so the renewal billing
 * clock starts: amount = per-domain price_override, else the TLD's enabled
 * renewal price; billing cycle from that price row's years (.gr biennial).
 *
 * An App\Action on purpose (portal-ready discipline): callable from Filament
 * today, from the customer portal tomorrow, with the guards inside the
 * transaction, not the UI.
 */
class AssignDomainToCustomer
{
    /** years → BillingCycle for domain renewals. */
    private const CYCLE_BY_YEARS = [
        1 => BillingCycle::Annual,
        2 => BillingCycle::Biennial,
        3 => BillingCycle::Triennial,
    ];

    public function __invoke(
        Domain $domain,
        Customer $customer,
        ?int $invoiceTypeId = null,
        ?int $paymentMethodId = null,
        float $vatPercent = 24.0,
    ): Domain {
        if ($domain->company_id !== $customer->company_id) {
            throw new RuntimeException('Ο πελάτης ανήκει σε άλλη εταιρεία.'); // tenant-safety belt
        }

        return DB::transaction(function () use ($domain, $customer, $invoiceTypeId, $paymentMethodId, $vatPercent): Domain {
            // Lock + re-check so a concurrent double-assign can't create two
            // contracts for one domain (the ConvertQuoteToServiceContract idiom).
            $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->first();
            if ($locked === null || $locked->customer_id !== null) {
                throw new RuntimeException('Το domain έχει ήδη ανατεθεί σε πελάτη.');
            }

            // A terminal domain must never start an Active billing clock — a
            // transferred-away/cancelled/deleted name would bill the customer
            // for something the tenant no longer holds (money-wrong direction).
            if ($locked->status instanceof DomainStatus && $locked->status->isTerminal()) {
                throw new RuntimeException(
                    'Το domain είναι σε κατάσταση «'.$locked->status->getLabel().'» — δεν ξεκινά χρέωση ανανέωσης. Διορθώστε πρώτα την κατάσταση αν είναι λάθος.'
                );
            }

            [$amount, $years] = $this->renewalPricing($locked);

            $contract = ServiceContract::create([
                'company_id' => $locked->company_id,
                'customer_id' => $customer->id,
                'invoice_type_id' => $invoiceTypeId,
                'payment_method_id' => $paymentMethodId,
                'description' => 'Ανανέωση domain '.$locked->fqdn,
                'billing_cycle' => self::CYCLE_BY_YEARS[$years]->value,
                'quantity' => 1,
                'amount' => round($amount, 2),
                'vat_percent' => $vatPercent,
                'status' => ServiceContractStatus::Active->value,
                'start_date' => ($locked->registered_at ?? Carbon::today())->toDateString(),
                // The billing clock starts at the registrar expiry: the renewal
                // draft is staged for the period that begins when the current
                // registration runs out (the two-clocks discipline, §3.4).
                'next_due_date' => ($locked->expires_at ?? Carbon::today())->toDateString(),
                'provisioning_module' => 'none',
                'domain' => $locked->fqdn,
            ]);

            $locked->update([
                'customer_id' => $customer->id,
                'service_contract_id' => $contract->id,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * The renewal amount + term for the domain: per-domain override first (its
     * term = the TLD's min_years), else the TLD's smallest ENABLED renewal
     * price row in EUR. Throws with an actionable message when unpriced — an
     * assignment must never silently start a €0 billing clock.
     *
     * @return array{0: float, 1: int}
     */
    private function renewalPricing(Domain $domain): array
    {
        $tld = $domain->tldRule;
        if ($tld === null) {
            throw new RuntimeException('Το domain δεν έχει TLD στον κατάλογο — προσθέστε το πρώτα στα «TLDs & τιμές».');
        }

        $years = max(1, (int) $tld->min_years);

        if ($domain->price_override !== null) {
            $this->assertSupportedTerm($years, $tld->tld);

            return [(float) $domain->price_override, $years];
        }

        $row = $tld->prices()
            ->where('operation', 'renewal')
            ->where('is_enabled', true)
            ->where('currency', 'EUR')
            ->whereNotNull('price')
            ->orderBy('years')
            ->first();

        if ($row === null) {
            throw new RuntimeException(
                'Δεν υπάρχει ενεργή τιμή ανανέωσης (EUR) για το .'.$tld->tld.' — ορίστε μία στα «TLDs & τιμές», ή price override στο domain.'
            );
        }

        $this->assertSupportedTerm((int) $row->years, $tld->tld);

        return [(float) $row->price, (int) $row->years];
    }

    private function assertSupportedTerm(int $years, string $tld): void
    {
        if (! isset(self::CYCLE_BY_YEARS[$years])) {
            throw new RuntimeException(
                "Η ανανέωση {$years} ετών του .{$tld} δεν αντιστοιχεί σε κύκλο χρέωσης (1/2/3 έτη) — ορίστε τιμή ανανέωσης 1–3 ετών."
            );
        }
    }
}
