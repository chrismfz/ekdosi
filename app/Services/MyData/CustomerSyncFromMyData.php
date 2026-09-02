<?php

namespace App\Services\MyData;

use App\Exceptions\Aade\AadeRegistryException;
use App\Models\Company;
use App\Models\Customer;
use App\Services\AadeRegistryLookup;
use App\Support\Afm;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Συγχρονισμός πελατών από myDATA — the customer twin of SupplierSyncFromMyData.
 *
 * Reuses {@see SalesReconciler::fetchAadeDocs} (RequestTransmittedDocs — OUR
 * sales over a window), collects the UNIQUE counterpart AFMs (the CUSTOMERS),
 * and upserts a `Customer` for each one we don't already have. Retail docs
 * (11.x) carry NO counterpart → only B2B customers are discovered, which is
 * exactly right. The bulk twin of the customer form's one-off GSIS «Άντληση».
 *
 * Name resolution mirrors the supplier sync:
 *   1. a name carried IN the doc (foreign counterparts send name; GR omitted);
 *   2. for GR AFMs (myDATA forbids the domestic name → only the AFM arrives),
 *      GSIS via {@see AadeRegistryLookup} when `$enrich` is on;
 *   3. otherwise an AFM-only customer for later manual fill.
 *
 * Tenant safety: runs from a Filament action (tenant in context) AND a CLI
 * command (no context). Customer carries a CompanyScope, but every query here
 * is ALSO scoped by `company_id` explicitly + every create sets it — never
 * relying on the ambient scope (CLAUDE.md CLI/queue rule).
 */
class CustomerSyncFromMyData
{
    public function __construct(
        private readonly Company $tenant,
        private readonly mixed $handler = null,
    ) {}

    public function sync(CarbonInterface $from, CarbonInterface $to, bool $enrich = true): CustomerSyncResult
    {
        // Init firebed (creds + the optional test handler) ourselves — the public
        // SalesReconciler::fetchAadeDocs does NOT (only reconcile() does), so a
        // standalone call would otherwise hit a stale/empty credential state.
        // Throws RuntimeException for non-GR / mode-off / missing creds.
        FirebedCredentials::init($this->tenant, $this->handler);

        // The handler is already wired into firebed's static state by init()
        // above; SalesReconciler's own handler arg is only used by ITS
        // initFirebed (which fetchAadeDocs never calls), so pass null and avoid
        // the ?MockHandler-vs-mixed type trap.
        $docs = (new SalesReconciler($this->tenant))->fetchAadeDocs(
            $from->format('d/m/Y'),
            $to->format('d/m/Y'),
        );

        $ourAfm = trim((string) ($this->tenant->afm ?? ''));

        // afm => the best (named-over-blank) counterpart name seen for it.
        /** @var array<string, string> $byAfm */
        $byAfm = [];
        $scannedDocs = 0;

        foreach ($docs as $doc) {
            $scannedDocs++;

            $afm = trim((string) ($doc->counterpartVat ?? ''));
            if ($afm === '' || Afm::uniqueKey($afm) === null) {
                continue; // retail / no counterpart / placeholder ΑΦΜ (no identity)
            }
            if ($ourAfm !== '' && $afm === $ourAfm) {
                continue; // never add ourselves
            }

            $name = trim((string) ($doc->counterpartName ?? ''));
            if (! array_key_exists($afm, $byAfm) || ($byAfm[$afm] === '' && $name !== '')) {
                $byAfm[$afm] = $name;
            }
        }

        return $this->upsertCustomers($byAfm, $scannedDocs, $enrich);
    }

    /**
     * @param  array<string, string>  $byAfm
     */
    private function upsertCustomers(array $byAfm, int $scannedDocs, bool $enrich): CustomerSyncResult
    {
        $created = 0;
        $skipped = 0;
        $enriched = 0;
        $named = 0;
        $nameless = 0;
        $createdAfms = [];
        $gsisFailures = [];

        foreach ($byAfm as $afm => $docName) {
            // Identity check on `afm_key` (the UNIQUE(company_id, afm_key)
            // constraint is the final guard). withTrashed: a soft-deleted
            // customer with this AFM was removed on purpose → never resurrect.
            $exists = Customer::afmOwnerQuery($this->tenant->getKey(), $afm)->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $attrs = [
                'company_id' => $this->tenant->getKey(),
                'afm' => $afm,
                'is_active' => true,
            ];

            if ($docName !== '') {
                // A name only arrives for FOREIGN counterparts (GR is omitted).
                $attrs['name'] = $docName;
                $named++;
            } elseif ($enrich) {
                // GR counterpart → resolve the domestic name from GSIS.
                $gsis = $this->enrichFromGsis($afm);
                if ($gsis !== null) {
                    $attrs += $gsis;
                    $enriched++;
                } else {
                    $gsisFailures[] = $afm;
                    $nameless++;
                }
            } else {
                $nameless++;
            }

            // customers.name is NOT NULL (unlike suppliers): a name-less discovery
            // (GR no-name + enrich off, or a GSIS miss) gets an «ΑΦΜ …» placeholder
            // the operator replaces — never a blank.
            if (! isset($attrs['name']) || trim((string) $attrs['name']) === '') {
                $attrs['name'] = 'ΑΦΜ '.$afm;
            }

            try {
                Customer::create($attrs);
            } catch (UniqueConstraintViolationException) {
                // Created meanwhile (a parallel run / the panel) — that's a skip, not a crash.
                $skipped++;

                continue;
            }
            $created++;
            $createdAfms[] = $afm;
        }

        return new CustomerSyncResult(
            scannedDocs: $scannedDocs,
            uniqueAfms: count($byAfm),
            created: $created,
            skippedExisting: $skipped,
            enrichedViaGsis: $enriched,
            namedFromDoc: $named,
            nameless: $nameless,
            createdAfms: $createdAfms,
            gsisFailures: $gsisFailures,
        );
    }

    /**
     * GSIS lookup → customer columns (same mapping as the customer form's AADE
     * crosscheck). Returns null on any failure so a bulk run never aborts.
     *
     * @return array<string, string>|null
     */
    private function enrichFromGsis(string $afm): ?array
    {
        try {
            $rec = app(AadeRegistryLookup::class, ['tenant' => $this->tenant])->findByAfm($afm);
        } catch (AadeRegistryException $e) {
            Log::info('Customer sync: GSIS enrichment skipped', [
                'company_id' => $this->tenant->getKey(),
                'afm' => $afm,
                'reason' => class_basename($e),
            ]);

            return null;
        }

        $out = [
            'name' => $rec->name,
            'tax_office' => $rec->doy,
            'address1' => $rec->address,
            'city' => $rec->city,
            'postcode' => $rec->postcode,
            'country' => 'GR',
        ];

        if (($primary = $rec->primaryActivity()) !== null && ! empty($primary['description'])) {
            $out['occupation'] = $primary['description'];
        }

        return array_filter($out, static fn ($v): bool => trim((string) $v) !== '');
    }
}
