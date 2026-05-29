<?php

namespace App\Services\MyData;

use App\Exceptions\Aade\AadeRegistryException;
use App\Models\Company;
use App\Models\Supplier;
use App\Services\AadeRegistryLookup;
use Carbon\CarbonInterface;
use Firebed\AadeMyData\Http\RequestDocs;
use Firebed\AadeMyData\Models\ContinuationToken;
use Firebed\AadeMyData\Models\Issuer;
use Illuminate\Support\Facades\Log;

/**
 * Συγχρονισμός προμηθευτών από myDATA (the "sync" provenance, E-phase).
 *
 * Reads myDATA `RequestDocs` (the παραστατικά OTHERS filed against us — i.e.
 * our expenses) over a date window, collects the UNIQUE issuer AFMs, and
 * upserts a `Supplier` (`source=sync`) for each one we don't already have —
 * exactly the "αδέσποτος προμηθευτής" case from the phase plan. This is the
 * bulk twin of the supplier form's one-off "Άντληση από ΑΑΔΕ" button.
 *
 * It deliberately does NOT touch the `expenses` tables (not built yet): its
 * only job is to populate Προμηθευτές from real myDATA traffic, and it's the
 * reusable building block the future `ExpenseReconciler` (E3) will lean on.
 *
 * Name resolution, in order:
 *   1. the name carried IN the doc (foreign issuers send name+address);
 *   2. for GR issuers (myDATA forbids the domestic name → only the AFM
 *      arrives), GSIS via {@see AadeRegistryLookup} when `$enrich` is on;
 *   3. otherwise create a name-less supplier (AFM only) for later manual fill.
 *
 * Tenant safety: this runs from BOTH a Filament action (tenant in context)
 * and a CLI command (no context). Per the CLAUDE.md latent-items note,
 * Supplier has no global company scope, so EVERY query here is scoped by
 * `company_id` explicitly and every create sets it — we never rely on
 * BelongsToTenant. Mirrors the WHMCS CLI paths.
 */
class SupplierSyncFromMyData
{
    public function __construct(
        private readonly Company $tenant,
        private readonly mixed $handler = null,
    ) {}

    public function sync(CarbonInterface $from, CarbonInterface $to, bool $enrich = true): SupplierSyncResult
    {
        // Throws RuntimeException for non-GR / mode-off / missing creds —
        // callers surface it. Wires the (optional) test Guzzle handler.
        FirebedCredentials::init($this->tenant, $this->handler);

        $fromStr = $from->format('d/m/Y');
        $toStr = $to->format('d/m/Y');

        $ourAfm = trim((string) ($this->tenant->afm ?? ''));

        // Collect the richest issuer info per AFM across all pages. Prefer a
        // candidate carrying a name (same AFM recurs with/without one).
        /** @var array<string, Issuer> $byAfm */
        $byAfm = [];
        $scannedDocs = 0;

        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $action = new RequestDocs;

            // '' mark + dd/MM/yyyy required by firebed's GET contract; the
            // last two args drive continuationToken pagination.
            $response = $action->handle(
                '',
                $fromStr,
                $toStr,
                null,
                null,
                null,
                null,
                $nextPartitionKey,
                $nextRowKey,
            );

            // Empty windows: AADE returns an empty/absent <invoicesDoc>,
            // which firebed stores as a scalar/null — the typed getter would
            // TypeError. Read raw + is_iterable, exactly like SalesReconciler.
            $invoicesDoc = $response->get('invoicesDoc');
            if (is_iterable($invoicesDoc)) {
                foreach ($invoicesDoc as $doc) {
                    $scannedDocs++;

                    $issuer = $doc->getIssuer();
                    if (! $issuer instanceof Issuer) {
                        continue;
                    }

                    $afm = trim((string) ($issuer->getVatNumber() ?? ''));
                    if ($afm === '') {
                        continue; // can't key a supplier without an AFM
                    }

                    // Never add ourselves as a supplier (self-billing /
                    // type 9.3 self-accounting docs carry our own AFM).
                    if ($ourAfm !== '' && $afm === $ourAfm) {
                        continue;
                    }

                    // Keep the candidate that carries a name over a bare one.
                    $existing = $byAfm[$afm] ?? null;
                    if ($existing === null || (empty($existing->getName()) && ! empty($issuer->getName()))) {
                        $byAfm[$afm] = $issuer;
                    }
                }
            }

            $token = $response->get('continuationToken');
            $token = $token instanceof ContinuationToken ? $token : null;
            $nextPartitionKey = $token?->getNextPartitionKey();
            $nextRowKey = $token?->getNextRowKey();
        } while ($token !== null && (! empty($nextPartitionKey) || ! empty($nextRowKey)));

        return $this->upsertSuppliers($byAfm, $scannedDocs, $enrich);
    }

    /**
     * @param  array<string, Issuer>  $byAfm
     */
    private function upsertSuppliers(array $byAfm, int $scannedDocs, bool $enrich): SupplierSyncResult
    {
        $created = 0;
        $skipped = 0;
        $enriched = 0;
        $named = 0;
        $nameless = 0;
        $createdAfms = [];
        $gsisFailures = [];

        foreach ($byAfm as $afm => $issuer) {
            // withTrashed: a soft-deleted supplier still occupies the
            // (company_id, afm) unique index AND signals an operator removed
            // it on purpose — never silently resurrect. Count as skipped.
            $exists = Supplier::withTrashed()
                ->where('company_id', $this->tenant->getKey())
                ->where('afm', $afm)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $attrs = [
                'company_id' => $this->tenant->getKey(),
                'afm' => $afm,
                'source' => 'sync',
                'country' => $this->issuerCountry($issuer),
                'is_active' => true,
            ];

            $docName = trim((string) ($issuer->getName() ?? ''));
            $isGreek = $attrs['country'] === 'GR';

            if ($docName !== '') {
                // Foreign issuers (and the rare GR doc that carries one).
                $attrs['name'] = $docName;
                $attrs += $this->addressFromIssuer($issuer);
                $named++;
            } elseif ($enrich && $isGreek) {
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

            Supplier::create($attrs);
            $created++;
            $createdAfms[] = $afm;
        }

        return new SupplierSyncResult(
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

    /** ISO-2 issuer country; default GR when myDATA omits it (domestic norm). */
    private function issuerCountry(Issuer $issuer): string
    {
        $c = strtoupper(trim((string) ($issuer->getCountry() ?? '')));

        return $c !== '' ? substr($c, 0, 2) : 'GR';
    }

    /**
     * Map the doc's <address> onto supplier columns (foreign issuers only —
     * GR docs don't carry one).
     *
     * @return array<string, string>
     */
    private function addressFromIssuer(Issuer $issuer): array
    {
        $address = $issuer->getAddress();
        if ($address === null) {
            return [];
        }

        $street = trim((string) ($address->getStreet() ?? ''));
        $number = trim((string) ($address->getNumber() ?? ''));
        $line = trim($street.' '.$number);

        $out = [];
        if ($line !== '') {
            $out['address1'] = $line;
        }
        if (($city = trim((string) ($address->getCity() ?? ''))) !== '') {
            $out['city'] = $city;
        }
        if (($pc = trim((string) ($address->getPostalCode() ?? ''))) !== '') {
            $out['postcode'] = $pc;
        }

        return $out;
    }

    /**
     * GSIS lookup → supplier columns, mirroring the supplier form's
     * "Άντληση από ΑΑΔΕ" mapping. Returns null on any failure (missing
     * creds / not found / unreachable) so a bulk run never aborts — the
     * AFM-only supplier is created instead and reported for manual fill.
     *
     * @return array<string, string>|null
     */
    private function enrichFromGsis(string $afm): ?array
    {
        try {
            $rec = app(AadeRegistryLookup::class, ['tenant' => $this->tenant])->findByAfm($afm);
        } catch (AadeRegistryException $e) {
            // Base of AadeAfmNotFound / AadeCredentialsInvalid / AadeUnreachable
            // (SOAP faults are wrapped into these). Catching the base keeps a
            // bulk run resilient — one bad AFM never aborts the sweep — and
            // auto-covers any future subtype, without swallowing unrelated bugs.
            Log::info('Supplier sync: GSIS enrichment skipped', [
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

        // Drop empties so we don't overwrite with blank strings.
        return array_filter($out, static fn ($v): bool => trim((string) $v) !== '');
    }
}
