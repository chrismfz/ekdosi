<?php

namespace App\Services\Taric;

use App\Models\CnCode;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use XMLReader;

/**
 * The Συνδυασμένη Ονοματολογία reference list (`cn_codes`, GLOBAL) behind the products'
 * TARIC: search for the product form, the yearly refresh from the EU's official open data
 * (Publications Office — data.europa.eu «combined-nomenclature-{year}», SKOS/RDF with Greek
 * labels, © European Union, CC BY 4.0), and the per-tenant list of products whose code no
 * longer exists in the latest year (the CN changes every 1 January).
 */
class CnCatalog
{
    private const DATASET_API = 'https://data.europa.eu/api/hub/search/datasets/combined-nomenclature-%d';

    public function latestYear(): ?int
    {
        $y = CnCode::query()->max('year');

        return $y === null ? null : (int) $y;
    }

    /**
     * Select options for the product form: code-prefix (digits, spaces ignored) or words in
     * the Greek path (every word must match). Keys are the 10-character TaricNo (CN + «00»).
     *
     * @return array<string, string>
     */
    public function search(string $query, int $limit = 50): array
    {
        $year = $this->latestYear();
        if ($year === null || trim($query) === '') {
            return [];
        }

        $q = CnCode::query()->where('year', $year);
        $digits = preg_replace('/\D+/', '', $query) ?? '';
        // A code query: only digits and separators («8471 30», «8471.30.00»).
        if ($digits !== '' && preg_match('/^[\d\s.\-]+$/', trim($query))) {
            $q->where('code', 'like', substr($digits, 0, 8).'%');
        } else {
            foreach (preg_split('/\s+/u', trim($query)) ?: [] as $word) {
                $q->where('path_el', 'like', '%'.addcslashes($word, '%_\\').'%');
            }
        }

        return $q->orderBy('code')->limit($limit)->get(['code', 'description_el', 'path_el'])
            ->mapWithKeys(fn (CnCode $c) => [$c->code.'00' => self::label($c)])
            ->all();
    }

    /** Label for a stored 10-char code (latest year), or null when it's not in the list. */
    public function labelFor(?string $taric): ?string
    {
        if (blank($taric)) {
            return null;
        }
        $c = CnCode::query()->where('year', $this->latestYear() ?? 0)->where('code', substr((string) $taric, 0, 8))->first();

        return $c ? self::label($c) : null;
    }

    public static function label(CnCode $c): string
    {
        $code = substr($c->code, 0, 4).' '.substr($c->code, 4, 2).' '.substr($c->code, 6, 2);
        $short = fn (string $t, int $n) => mb_strlen($t) > $n ? rtrim(mb_substr($t, 0, $n)).'…' : $t;
        // «code — leaf · heading»: the leaf alone is often just «Άλλοι», the 4-digit heading
        // (first path segment) says what it is. Full path stays searchable.
        $heading = trim((string) preg_replace('/^\d{4}\s*/', '', explode(' › ', $c->path_el)[0]));
        $leaf = $c->description_el;

        return $heading === $leaf || $heading === ''
            ? "{$code} — ".$short($leaf, 160)
            : "{$code} — ".$short($leaf, 90).' · '.$short($heading, 90);
    }

    /**
     * This tenant's products whose code isn't in the LATEST year (abolished / renumbered).
     *
     * @return Collection<int, Product>
     */
    public function obsoleteProducts(Company $tenant): Collection
    {
        $year = $this->latestYear();
        if ($year === null) {
            return collect();
        }

        return Product::query()
            ->where('company_id', $tenant->getKey())
            ->whereNotNull('taric_code')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('cn_codes')
                ->where('cn_codes.year', $year)
                ->whereColumn('cn_codes.code', DB::raw('SUBSTR(products.taric_code, 1, 8)')))
            ->orderBy('description_short')
            ->get(['id', 'description_short', 'taric_code']);
    }

    /**
     * Download the year's CN from the EU's open data and REPLACE that year's rows.
     * Heavy (≈170 MB RDF) → run from the queue (ImportCnCatalog) or the CLI, never a web request.
     *
     * @return array{year: int, count: int, source: string}
     */
    public function importFromEu(int $year, int $minRows = 1000): array
    {
        $url = $this->rdfUrl($year);
        self::assertEuropaHost($url);
        $tmp = tempnam(sys_get_temp_dir(), 'cn');   // XMLReader doesn't need an extension
        try {
            Http::timeout(600)
                // Every redirect hop must stay on an EU host too (the first URL came from an API response).
                ->withOptions(['allow_redirects' => [
                    'max' => 5, 'protocols' => ['https'],
                    'on_redirect' => fn ($request, $response, $uri) => self::assertEuropaHost((string) $uri),
                ]])
                ->sink($tmp)->get($url)->throw();

            return ['year' => $year, 'count' => $this->importRdf($tmp, $year, $minRows), 'source' => $url];
        } finally {
            @unlink($tmp);
        }
    }

    /** Parse a CN SKOS/RDF file (streaming) and replace the year's rows. Returns the row count. */
    public function importRdf(string $path, int $year, int $minRows = 1000): int
    {
        $labels = $this->greekLabelsByCode($path);
        $rows = [];
        foreach ($labels as $code => $label) {
            if (strlen((string) $code) !== 8) {
                continue;
            }
            $parts = [];
            foreach ([substr((string) $code, 0, 4), substr((string) $code, 0, 6)] as $p) {
                $t = self::clean($labels[$p] ?? '');
                if ($t !== '' && ! in_array($t, $parts, true)) {
                    $parts[] = $t;
                }
            }
            $leaf = self::clean($label);
            if ($leaf !== '' && ! in_array($leaf, $parts, true)) {
                $parts[] = $leaf;
            }
            $rows[] = ['code' => (string) $code, 'description_el' => $leaf, 'path_el' => implode(' › ', $parts)];
        }

        if (count($rows) < $minRows) {
            // A real CN has ~9–10k 8-digit codes: fewer means a wrong/partial file — never
            // wipe a good year with it.
            throw new RuntimeException('Το αρχείο ΣΟ '.$year.' έδωσε μόνο '.count($rows).' κωδικούς — δεν εφαρμόστηκε.');
        }

        DB::transaction(function () use ($rows, $year) {
            CnCode::query()->where('year', $year)->delete();
            $now = now();
            foreach (array_chunk($rows, 500) as $chunk) {
                CnCode::query()->insert(array_map(fn ($r) => $r + ['year' => $year, 'created_at' => $now, 'updated_at' => $now], $chunk));
            }
        });

        return count($rows);
    }

    /** Resolve the SKOS «core» RDF distribution of the year's dataset via the data.europa.eu API. */
    private function rdfUrl(int $year): string
    {
        $meta = Http::timeout(60)->get(sprintf(self::DATASET_API, $year));
        if (! $meta->successful()) {
            throw new RuntimeException("Δεν βρέθηκε η ΣΟ {$year} στο data.europa.eu (HTTP {$meta->status()}) — ίσως δεν έχει δημοσιευτεί ακόμα (συνήθως Οκτώβριο).");
        }
        $urls = [];
        foreach ((array) data_get($meta->json(), 'result.distributions', []) as $d) {
            $u = data_get($d, 'access_url');
            $u = is_array($u) ? ($u[0] ?? null) : $u;
            if (is_string($u) && str_contains(strtolower($u), '.rdf')) {
                $urls[] = $u;
            }
        }
        // Prefer the plain SKOS core file (ESTAT-CN{year}.rdf) over the AP-EU profile.
        usort($urls, fn ($a, $b) => (int) str_contains($a, 'skos-ap-eu') <=> (int) str_contains($b, 'skos-ap-eu'));

        return $urls[0] ?? throw new RuntimeException("Η ΣΟ {$year} δεν έχει διανομή RDF στο data.europa.eu.");
    }

    /** The download URL comes from the API response — fetch it only from an EU host. */
    private static function assertEuropaHost(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! ($host === 'europa.eu' || str_ends_with($host, '.europa.eu'))) {
            throw new RuntimeException('Μη αναμενόμενη πηγή λήψης ΣΟ: '.$url);
        }
    }

    /** @return array<string, string> digits (4/6/8) → Greek prefLabel */
    private function greekLabelsByCode(string $path): array
    {
        $skos = 'http://www.w3.org/2004/02/skos/core#';
        $out = [];
        $r = new XMLReader;
        if (! $r->open($path)) {
            throw new RuntimeException('Δεν ανοίγει το αρχείο ΣΟ.');
        }
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'Description') {
                continue;
            }
            $node = $r->expand();
            if (! $node instanceof \DOMElement) {
                continue;
            }
            $notation = $node->getElementsByTagNameNS($skos, 'notation')->item(0)?->textContent;
            $digits = preg_replace('/\D+/', '', (string) $notation) ?? '';
            if (! in_array(strlen($digits), [4, 6, 8], true) || isset($out[$digits])) {
                continue;
            }
            foreach ($node->getElementsByTagNameNS($skos, 'prefLabel') as $l) {
                if ($l->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') === 'el') {
                    $out[$digits] = (string) preg_replace('/^\s*[\d ]+\s*-\s*/u', '', trim($l->textContent));
                    break;
                }
            }
        }
        $r->close();

        return $out;
    }

    private static function clean(string $t): string
    {
        return trim((string) preg_replace('/^[-–\s]+/u', '', $t));
    }
}
