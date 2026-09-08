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
            if ($expiresAt === null && ($row['expires_at'] ?? null) !== null && trim((string) $row['expires_at']) !== '') {
                // Unparseable date: import the name anyway (unbillable — the
                // assign guard requires expires_at) but say so.
                $warn("Το {$fqdn} έχει μη αναγνωρίσιμη ημερομηνία λήξης «{$row['expires_at']}» — εισήχθη χωρίς λήξη.");
            }

            $tldRule = DomainTld::withTrashed()
                ->where('company_id', $company->id)
                ->where('tld', $tld)
                ->first();
            if ($tldRule !== null && $tldRule->trashed()) {
                $counts['skipped']++;
                $warn("Παράλειψη {$fqdn}: το TLD .{$tld} είναι διαγραμμένο στον κατάλογο «TLDs & τιμές».");

                continue;
            }
            if ($tldRule === null) {
                $tldRule = DomainTld::create([
                    'company_id' => $company->id,
                    'tld' => $tld,
                    'registrar_connection_id' => null, // manual — grEPP routing is A4
                    'min_years' => 1,
                    'is_active' => true,
                ]);
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
                Domain::create([
                    'company_id' => $company->id,
                    'customer_id' => null, // αδέσποτο — ONLY the operator assigns
                    'domain_tld_id' => $tldRule->id,
                    'sld' => $sld,
                    'tld' => $tld,
                    'fqdn' => $fqdn,
                    'status' => DomainStatus::Active,
                    'expires_at' => $expiresAt,
                    'auto_renew' => false, // option β — assignment turns it on
                    'module_meta' => ['imported_from' => 'csv'],
                ]);
                $counts['created']++;

                continue;
            }

            // Existing live row: the CSV may only fill/refresh the expiry —
            // never customer_id, auto_renew, status, routing or overrides.
            if ($expiresAt !== null && $expiresAt !== $domain->expires_at?->toDateString()) {
                $domain->forceFill(['expires_at' => $expiresAt])->save();
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
        foreach (self::DATE_FORMATS as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue; // modern Carbon throws instead of returning false
            }
            // Round-trip check: rejects «31/02/2026»-style overflow parses.
            if ($parsed !== null && $parsed->format($format) === $value) {
                return $parsed->toDateString();
            }
        }

        return null;
    }
}
