<?php

namespace App\Services\Assistant\Tools;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\InvoiceScope;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Search LIVE παραστατικά by what's INSIDE them — line descriptions and/or the
 * operator's internal notes — plus an exact whmcs_invoice_id lookup. Answers the
 * "ψάξε γραμμές" questions the aggregate tools can't:
 *   «πόσα παραστατικά ανανέωσαν το myip.gr» (γραμμές LIKE 'myip.gr'),
 *   «ποιο παραστατικό αναφέρει το WHMCS #32144 στις σημειώσεις»,
 *   «ποιο ekdosi παραστατικό είναι δεμένο με το whmcs 32144».
 * Read-only, tenant-scoped. Returns the total match COUNT + a capped sample with
 * the matching snippet + a link to each.
 */
class SearchInvoicesTool implements AssistantTool
{
    public function name(): string
    {
        return 'search_invoices';
    }

    public function description(): string
    {
        return 'Αναζήτηση παραστατικών με βάση το ΠΕΡΙΕΧΟΜΕΝΟ τους: κείμενο στις ΓΡΑΜΜΕΣ ή/και στις '
            .'εσωτερικές ΣΗΜΕΙΩΣΕΙΣ, ή ακριβές `whmcs_invoice_id`. Επιστρέφει συνολικό πλήθος + δείγμα με το '
            .'σημείο που ταίριαξε και link. Για «πόσα παραστατικά ανανέωσαν το myip.gr» (`query`="myip.gr", '
            .'`in`="lines"), «ποιο παραστατικό αναφέρει WHMCS #X στις σημειώσεις» (`in`="notes"), «το ekdosi '
            .'παραστατικό του whmcs 32144» (`whmcs_invoice_id`). Μετράει μόνο ζωντανά (μη ακυρωμένα) παραστατικά.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Κείμενο προς αναζήτηση (π.χ. domain, όνομα υπηρεσίας, WHMCS #id).'],
                'in' => ['type' => 'string', 'description' => 'Πού να ψάξει: "lines" (γραμμές, προεπιλογή), "notes" (σημειώσεις), "both".'],
                'whmcs_invoice_id' => ['type' => 'integer', 'description' => 'Ακριβής αναζήτηση κατά WHMCS invoice id (ντετερμινιστικός σύνδεσμος).'],
                'from' => ['type' => 'string', 'description' => 'Προαιρετικά: από ημ/νία έκδοσης (YYYY-MM-DD).'],
                'to' => ['type' => 'string', 'description' => 'Προαιρετικά: έως ημ/νία έκδοσης (YYYY-MM-DD).'],
                'limit' => ['type' => 'integer', 'description' => 'Μέγεθος δείγματος (1–50, προεπιλογή 20). Το πλήθος μετριέται πάντα ολόκληρο.'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:Invoice';
    }

    public function run(Company $tenant, array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        // Default 'lines' (also when the key is absent OR an unknown value is
        // passed) — a null $in used to silently drop the text filter and match
        // every invoice.
        $in = $input['in'] ?? 'lines';
        if (! in_array($in, ['lines', 'notes', 'both'], true)) {
            $in = 'lines';
        }
        $whmcsId = (int) ($input['whmcs_invoice_id'] ?? 0);
        $limit = max(1, min(50, (int) ($input['limit'] ?? 20)));
        $from = trim((string) ($input['from'] ?? ''));
        $to = trim((string) ($input['to'] ?? ''));

        if ($query === '' && $whmcsId <= 0) {
            return ['error' => 'Δώσε `query` (κείμενο) ή `whmcs_invoice_id`.'];
        }

        $searchLines = in_array($in, ['lines', 'both'], true);
        $searchNotes = in_array($in, ['notes', 'both'], true);

        // A whmcs-id lookup is deterministic and IGNORES the text query — so the
        // text is neither echoed nor used to build snippets (else the result would
        // claim rows «matched» a query that never filtered them).
        $textQuery = $whmcsId > 0 ? '' : $query;

        $base = Invoice::query()->where('invoices.company_id', $tenant->getKey());

        if ($whmcsId > 0) {
            // Deterministic doc lookup — the whmcs id IS the filter; a stray
            // query/date is ignored so «το ekdosi του whmcs X» can't false-negative.
            // Returns it even if cancelled (parity with invoice_get).
            $base->where('whmcs_invoice_id', $whmcsId);
        } else {
            // A text search stays limited to LIVE invoices so «πόσα παραστατικά
            // ανανέωσαν το X» counts only real ones.
            InvoiceScope::live($base, 'invoices.');

            if ($from !== '') {
                $base->whereDate('invoices.issued_at', '>=', $from);
            }
            if ($to !== '') {
                $base->whereDate('invoices.issued_at', '<=', $to);
            }

            if ($query !== '') {
                // Escape the LIKE metacharacters so a query containing them matches
                // LITERALLY (this is a count tool — a stray % must not inflate the
                // headline). The escape char is '!' (NOT '\'): a backslash escape is
                // an unterminated-string syntax error on default-mode MariaDB, while
                // ESCAPE '!' is portable across MariaDB and sqlite.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query).'%';
                $base->where(function (Builder $w) use ($like, $searchLines, $searchNotes): void {
                    if ($searchLines) {
                        $w->orWhereHas('lines', fn (Builder $l) => $l->whereRaw("product_descr LIKE ? ESCAPE '!'", [$like]));
                    }
                    if ($searchNotes) {
                        $w->orWhereHas('internalNotes', fn (Builder $n) => $n->whereRaw("body LIKE ? ESCAPE '!'", [$like]));
                    }
                });
            }
        }

        // Full count first (headline), then a capped, detail-loaded sample.
        $count = (clone $base)->count();

        $invoices = $base
            ->with(['customer:id,name,afm', 'lines:id,invoice_id,product_descr', 'internalNotes:id,notable_type,notable_id,body'])
            ->orderByDesc('invoices.issued_at')
            ->limit($limit)
            ->get(['invoices.id', 'invoices.invcode', 'invoices.customer_id', 'invoices.issued_at', 'invoices.gross_total', 'invoices.mydata_state', 'invoices.whmcs_invoice_id']);

        return [
            'query' => $textQuery !== '' ? $textQuery : null,
            'in' => $textQuery !== '' ? $in : null,
            'whmcs_invoice_id' => $whmcsId > 0 ? $whmcsId : null,
            'count' => $count,
            'sample_size' => $invoices->count(),
            'currency' => 'EUR',
            'invoices' => $invoices->map(function (Invoice $inv) use ($tenant, $textQuery, $searchLines, $searchNotes): array {
                $matches = [];
                // mb_stripos: the DB matched (accent/case-insensitive collation);
                // a multibyte-aware re-check keeps the Greek snippet from coming
                // back empty where byte-wise stripos would miss.
                if ($textQuery !== '' && $searchLines) {
                    foreach ($inv->lines as $l) {
                        if ($l->product_descr !== null && self::contains($l->product_descr, $textQuery)) {
                            $matches[] = 'γραμμή: '.$l->product_descr;
                        }
                    }
                }
                if ($textQuery !== '' && $searchNotes) {
                    foreach ($inv->internalNotes as $n) {
                        if ($n->body !== null && self::contains($n->body, $textQuery)) {
                            $matches[] = 'σημείωση: '.$n->body;
                        }
                    }
                }
                if ($matches === [] && $textQuery !== '') {
                    // Defensive: the DB matched but the folded re-check didn't (an
                    // exotic collation fold). Show context from the SAME field
                    // searched (never an unsearched field) so a hit isn't evidence-free.
                    $ctx = $searchLines ? $inv->lines->first()?->product_descr : $inv->internalNotes->first()?->body;
                    if ($ctx !== null) {
                        $matches[] = '≈ '.$ctx;
                    }
                }

                return [
                    'code' => $inv->invcode,
                    'customer' => $inv->customer?->name,
                    'afm' => $inv->customer?->afm,
                    'date' => $inv->issued_at instanceof Carbon ? $inv->issued_at->format('Y-m-d') : (string) $inv->issued_at,
                    'gross' => round((float) $inv->gross_total, 2),
                    'mydata_state' => $inv->mydata_state ?? 'μη υποβληθέν',
                    'whmcs_invoice_id' => $inv->whmcs_invoice_id,
                    'matched' => array_slice($matches, 0, 5),
                    'url' => InvoiceResource::getUrl('view', ['record' => $inv->id, 'tenant' => $tenant]),
                ];
            })->all(),
        ];
    }

    /**
     * Does $haystack contain $needle, matching the DB's accent- and
     * case-insensitive Greek collation (utf8mb4_unicode_ci) — so the snippet
     * re-check agrees with the LIKE that produced the hit? `mb_stripos` alone is
     * only case-insensitive, so «Ανανέωση» would fail a match on «ανανεωση».
     */
    private static function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || str_contains(self::fold($haystack), self::fold($needle));
    }

    /** Lowercase + strip Greek tonal diacritics (and final sigma) for folded compare. */
    private static function fold(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ',
        ]);
    }
}
