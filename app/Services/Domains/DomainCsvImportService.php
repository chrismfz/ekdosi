<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainTld;
use Illuminate\Support\Carbon;

/**
 * CSV portfolio import (Πυλώνας A / A2c, README §9 .gr caveat) — the bootstrap
 * for registrars WITHOUT a listing API: the .gr registry's EPP has no
 * list-my-domains command, so the initial .gr list comes from a grweb portal
 * export (works for any TLD/file though). Same guards as the registrar-first
 * import — new domains land ΑΔΕΣΠΟΤΑ, customer_id/auto_renew are never written
 * on existing rows, tombstones and operator-deleted TLDs are never resurrected
 * — but deliberately NOT DomainSyncService::apply: a CSV is an operator file,
 * not the registrar clock, so it never stamps last_synced_at.
 *
 * TLD split = first dot (mysite.com.gr → sld «mysite», tld «com.gr» — the
 * registrable unit is the row itself, same as Domain::fqdnFor's convention).
 * A missing TLD-catalogue row is auto-created UNROUTED (manual) — .gr routing
 * to grEPP arrives with A4.
 */
class DomainCsvImportService
{
    /** Accepted expiry formats — grweb + the usual spreadsheet exports. */
    private const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'];

    /**
     * @param  iterable<array{fqdn: string, expires_at: ?string}>  $rows
     * @return array{created: int, updated: int, unchanged: int, skipped: int, invalid: int}
     */
    public function import(Company $company, iterable $rows, ?callable $warn = null): array
    {
        $warn ??= static function (string $message): void {};
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'invalid' => 0];
        /** @var array<string, DomainTld|false> $tldCache false = trashed (skip its rows) */
        $tldCache = [];

        foreach ($rows as $row) {
            $fqdn = mb_strtolower(trim(rtrim(trim((string) $row['fqdn']), '.')));
            if ($fqdn === '') {
                continue; // blank line — not even worth an «invalid»
            }
            $dot = mb_strpos($fqdn, '.');
            if ($dot === false || $dot === 0 || $dot === mb_strlen($fqdn) - 1 || str_contains($fqdn, ' ')) {
                $counts['invalid']++;
                $warn("Μη έγκυρο όνομα domain: «{$fqdn}» — η γραμμή παραλείφθηκε.");

                continue;
            }
            $sld = mb_substr($fqdn, 0, $dot);
            $tld = mb_substr($fqdn, $dot + 1);

            $expiresAt = $this->parseDate($row['expires_at'] ?? null);
            // Unparseable date: the name still imports (unbillable — the assign
            // guard requires expires_at) — but warn ONLY if the row actually
            // lands (the skip guards below may drop it entirely).
            $badDate = $expiresAt === null && trim((string) ($row['expires_at'] ?? '')) !== ''
                ? (string) $row['expires_at']
                : null;

            if (! array_key_exists($tld, $tldCache)) {
                $tldRule = DomainTld::withTrashed()
                    ->where('company_id', $company->id)
                    ->where('tld', $tld)
                    ->first();
                if ($tldRule !== null && $tldRule->trashed()) {
                    $warn("Παράλειψη domains σε .{$tld}: το TLD είναι διαγραμμένο στον κατάλογο «TLDs & τιμές».");
                    $tldRule = false;
                } elseif ($tldRule === null) {
                    $tldRule = DomainTld::create([
                        'company_id' => $company->id,
                        'tld' => $tld,
                        'registrar_connection_id' => null, // manual — grEPP routing is A4
                        'min_years' => 1,
                        'is_active' => true,
                    ]);
                }
                $tldCache[$tld] = $tldRule;
            }
            $tldRule = $tldCache[$tld];
            if ($tldRule === false) {
                $counts['skipped']++;

                continue;
            }

            $domain = Domain::withTrashed()
                ->where('company_id', $company->id)
                ->where('fqdn', $fqdn)
                ->first();
            if ($domain !== null && $domain->trashed()) {
                $counts['skipped']++; // tombstone — never resurrected

                continue;
            }

            if ($domain === null) {
                if ($badDate !== null) {
                    $warn("Το {$fqdn} έχει μη αναγνωρίσιμη ημερομηνία λήξης «{$badDate}» — εισήχθη χωρίς λήξη.");
                }
                // A lapsed expiry lands as Expired straight away — for .gr the
                // CSV is the ONLY truth source until grEPP (A4), so no sync
                // will ever derive it later (the API path derives via apply).
                $lapsed = $expiresAt !== null && Carbon::parse($expiresAt)->lt(Carbon::today());
                Domain::create([
                    'company_id' => $company->id,
                    'customer_id' => null, // αδέσποτο — ONLY the operator assigns
                    'domain_tld_id' => $tldRule->id,
                    'sld' => $sld,
                    'tld' => $tld,
                    'fqdn' => $fqdn,
                    'status' => $lapsed ? DomainStatus::Expired : DomainStatus::Active,
                    'expires_at' => $expiresAt,
                    'auto_renew' => false, // option β — assignment turns it on
                    'module_meta' => ['imported_from' => 'csv'],
                ]);
                $counts['created']++;

                continue;
            }

            // Operator-terminal row (cancelled/transferred_away): frozen —
            // the nightly sync refuses these and so does the CSV (blocksSync
            // parity: the recorded expiry is historical record).
            if ($domain->status instanceof DomainStatus && $domain->status->blocksSync()) {
                $counts['skipped']++;
                $warn("Παράλειψη {$fqdn}: κατάσταση «{$domain->status->getLabel()}» — παγωμένο (ιστορικό record).");

                continue;
            }

            if ($badDate !== null) {
                $warn("Το {$fqdn} έχει μη αναγνωρίσιμη ημερομηνία λήξης «{$badDate}» — η λήξη δεν άλλαξε.");
            }

            // Existing live row: the CSV may only fill/refresh the expiry —
            // never customer_id, auto_renew, routing or overrides. Status is
            // derived from the EFFECTIVE expiry (new ?? stored) on EVERY pass,
            // both directions — a re-import with the same past date must not
            // leave «Ενεργό» standing, and a grweb renewal must un-expire
            // (for .gr the CSV is the only truth source until grEPP/A4).
            $updates = [];
            if ($expiresAt !== null && $expiresAt !== $domain->expires_at?->toDateString()) {
                $updates['expires_at'] = $expiresAt;
            }
            $effective = $expiresAt ?? $domain->expires_at?->toDateString();
            if ($effective !== null) {
                $lapsed = Carbon::parse($effective)->lt(Carbon::today());
                if ($lapsed && $domain->status === DomainStatus::Active) {
                    $updates['status'] = DomainStatus::Expired;
                } elseif (! $lapsed && $domain->status === DomainStatus::Expired) {
                    $updates['status'] = DomainStatus::Active;
                }
            }
            if ($updates !== []) {
                $domain->forceFill($updates)->save();
                $counts['updated']++;
            } else {
                $counts['unchanged']++;
            }
        }

        return $counts;
    }

    private function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        // Zero-pad single-digit parts first («1/6/2027» — Greek-locale Excel
        // re-saves drop the padding) so the round-trip check below rejects
        // only REAL overflow («31/02/2026» → March), never lazy padding.
        $normalized = preg_replace('/(?<!\d)(\d)(?!\d)/', '0$1', $value) ?? $value;
        foreach (self::DATE_FORMATS as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $normalized);
            } catch (\Throwable) {
                continue; // modern Carbon throws instead of returning false
            }
            if ($parsed !== null && $parsed->format($format) === $normalized) {
                return $parsed->toDateString();
            }
        }

        return null;
    }
}
