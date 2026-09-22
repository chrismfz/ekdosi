<?php

namespace App\Services\Import;

use App\Models\Company;
use App\Support\Afm;
use App\Support\GreekText;
use App\Support\IsoCountry;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * CSV import of one entity (customers, products, suppliers) into a tenant.
 *
 * Two passes over the same code: plan() is the dry run the operator previews;
 * import() re-plans against the current data and writes the valid rows in ONE
 * transaction (rows with an error are skipped; a database failure writes
 * nothing). A row that matches an existing record only fills that record's
 * BLANK fields, so re-importing the same file is harmless. Every query and write names the company explicitly (no reliance on
 * the ambient tenant scope).
 *
 * Columns are recognised by header: the Greek/English aliases below plus the raw
 * column names of our own CSV export, compared case-, accent- and
 * punctuation-insensitively («Α.Φ.Μ.» = «ΑΦΜ» = «afm»). Unrecognised columns are
 * reported and ignored.
 */
abstract class EntityCsvImporter
{
    /** Genitive plural for headings: «Εισαγωγή πελατών». */
    abstract public function entityLabel(): string;

    /**
     * field key => label (the template header) + extra header aliases;
     * `template => false` = recognised when present but left out of the template.
     *
     * @return array<string, array{label: string, aliases: list<string>, template?: bool}>
     */
    abstract public function fields(): array;

    /** @return array<string, string> one illustrative row for the template */
    abstract public function sampleRow(): array;

    /** Decide what $row (field key => trimmed cell) does; fill in $planned. */
    abstract protected function planRow(Company $company, array $row, PlannedRow $planned): void;

    /** Write one CREATE/FILL row. */
    abstract protected function persist(Company $company, PlannedRow $row): void;

    /** @var (Closure(Model): bool)|null may the acting user fill this existing record? null = yes */
    private ?Closure $mayFill = null;

    /** Per-plan state (in-file duplicate detection, lookup caches). */
    protected function reset(): void {}

    /**
     * Gate the FILL of an existing record (e.g. the operator's update permission):
     * a record the gate refuses is left untouched and flagged.
     *
     * @param  Closure(Model): bool  $gate
     */
    public function withFillGate(Closure $gate): static
    {
        $this->mayFill = $gate;

        return $this;
    }

    public function plan(Company $company, CsvTable $table): CsvImportPlan
    {
        $this->reset();
        [$columns, $mapping, $ignored] = $this->mapHeaders($table->headers);

        $rows = [];
        foreach ($table->rows as $raw) {
            $row = [];
            foreach ($columns as $field => $index) {
                $row[$field] = $raw['cells'][$index] ?? '';
            }

            $planned = new PlannedRow($raw['line']);
            if ($columns === []) {
                $planned->fail('Καμία στήλη δεν αναγνωρίστηκε.');
            } else {
                $this->planRow($company, $row, $planned);
            }
            $rows[] = $planned;
        }

        return new CsvImportPlan($mapping, $ignored, $rows);
    }

    public function import(Company $company, CsvTable $table): CsvImportPlan
    {
        $plan = $this->plan($company, $table);

        DB::transaction(function () use ($company, $plan): void {
            foreach ($plan->rows as $row) {
                if ($row->action === PlannedRow::CREATE || $row->action === PlannedRow::FILL) {
                    $this->persist($company, $row);
                }
            }
        });

        return $plan;
    }

    /** A ready-to-fill CSV: `;`-separated (Greek Excel's list separator) with a UTF-8 BOM. */
    public function template(): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        $fields = array_filter($this->fields(), static fn (array $def): bool => $def['template'] ?? true);
        fputcsv($fh, array_column($fields, 'label'), ';', '"', '');
        $sample = $this->sampleRow();
        fputcsv($fh, array_map(static fn (string $f): string => $sample[$f] ?? '', array_keys($fields)), ';', '"', '');
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /** «Α.Φ.Μ.» → «αφμ»: lower-case, no Greek accents, letters/digits only. */
    public static function normalizeHeader(string $header): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', GreekText::fold($header)) ?? '';
    }

    /**
     * @param  list<string>  $headers
     * @return array{0: array<string, int>, 1: array<string, string>, 2: list<string>}
     *                                                                                 [field => column index, field => header text, ignored headers]
     */
    private function mapHeaders(array $headers): array
    {
        $lookup = [];
        foreach ($this->fields() as $field => $def) {
            foreach ([$field, $def['label'], ...$def['aliases']] as $alias) {
                $lookup[self::normalizeHeader($alias)] ??= $field;
            }
        }

        $columns = $mapping = $ignored = [];
        foreach ($headers as $index => $header) {
            $field = $lookup[self::normalizeHeader($header)] ?? null;
            if ($field === null || isset($columns[$field])) {
                if (trim($header) !== '') {
                    $ignored[] = $header;
                }

                continue;
            }
            $columns[$field] = $index;
            $mapping[$field] = $header;
        }

        return [$columns, $mapping, $ignored];
    }

    /* ───────────── shared cell readers ───────────── */

    /** Trimmed text or null; a value longer than the column fails the row. */
    protected function text(array $row, string $field, int $max, PlannedRow $planned): ?string
    {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $planned->fail("«{$this->fields()[$field]['label']}» ξεπερνά τους {$max} χαρακτήρες.");

            return null;
        }

        return $value;
    }

    /** An email, or null with a warning when it isn't one. */
    protected function email(array $row, string $field, int $max, PlannedRow $planned): ?string
    {
        $value = $this->text($row, $field, $max, $planned);
        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $planned->warn("Μη έγκυρο email «{$value}» — αγνοήθηκε.");

            return null;
        }

        return $value;
    }

    /** The country a party is judged by: the file's column, else its VAT prefix, else the tenant's own. */
    protected function effectiveCountry(?string $fileCountry, array $row, string $afmField, Company $company): ?string
    {
        return $fileCountry
            ?? Afm::countryPrefix(trim((string) ($row[$afmField] ?? '')))
            ?? $company->country_code;
    }

    /** An ISO-3166 alpha-2 code from «GR» / «Ελλάδα» / «Greece»; unknown → null with a warning. */
    protected function country(array $row, string $field, PlannedRow $planned): ?string
    {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value === '') {
            return null;
        }
        $code = IsoCountry::tryNormalise($value);
        if ($code === null) {
            $planned->warn("Άγνωστη χώρα «{$value}» — αγνοήθηκε.");
        }

        return $code;
    }

    /**
     * The ΑΦΜ as its identity key («EL 123-456-789» → «123456789»), or null for
     * blank / placeholder / free text (a warning when something was typed). A
     * Greek ΑΦΜ must pass its check digit — a wrong one would break every myDATA
     * filing for that party — so a bad one fails the row.
     *
     * $country is the party's EFFECTIVE country: the file's column, else a VAT
     * prefix, else the importing tenant's own country — never «Greece» by default
     * (an Estonian tenant's bare registry code is not a Greek ΑΦΜ).
     */
    protected function afm(array $row, string $field, ?string $country, PlannedRow $planned): ?string
    {
        $raw = trim((string) ($row[$field] ?? ''));
        if ($raw === '') {
            return null;
        }

        // A spreadsheet stores «094019245» as the number 94019245: give a Greek
        // ΑΦΜ back the leading zeros it lost (the check digit then vouches for it).
        if ($country === 'GR' && preg_match('/^\d{7,8}$/', $raw) === 1) {
            $raw = str_pad($raw, 9, '0', STR_PAD_LEFT);
        }

        $key = Afm::uniqueKey($raw);
        if ($key === null) {
            if (! Afm::isPlaceholder(Afm::digits($raw))) {
                $planned->warn("Το ΑΦΜ «{$raw}» δεν είναι έγκυρο — καταχωρίζεται χωρίς ΑΦΜ.");
            }

            return null;
        }

        if ($country === 'GR' && Afm::greekChecksumOk($key) === false) {
            $planned->fail("Το ΑΦΜ «{$raw}» δεν περνά τον έλεγχο εγκυρότητας.");

            return null;
        }

        if (strlen($key) > 20) {   // the afm columns are varchar(20)
            $planned->fail("Το ΑΦΜ «{$raw}» είναι πολύ μεγάλο — ένα ΑΦΜ ανά κελί.");

            return null;
        }

        return $key;
    }

    /**
     * A decimal typed either way — «12,50», «1.234,56», «12.50», «€ 12» — or null
     * when blank. Unreadable or negative fails the row.
     */
    protected function decimal(array $row, string $field, PlannedRow $planned): ?float
    {
        $value = str_replace(['€', ' ', "\u{00A0}", '%'], '', trim((string) ($row[$field] ?? '')));
        if ($value === '') {
            return null;
        }

        // «1.200» / «1,200» mean 1200 in one locale and 1.2 in the other — refuse to guess.
        if (preg_match('/^\d{1,3}([.,])\d{3}(\1\d{3})*$/', $value, $m) === 1) {
            $planned->fail("«{$this->fields()[$field]['label']}»: διφορούμενη τιμή «{$row[$field]}» — γράψε την χωρίς διαχωριστικό χιλιάδων (π.χ. ".str_replace($m[1], '', $value).').');

            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $value = str_replace(',', '.', str_replace('.', '', $value));   // 1.234,56
        } else {
            $value = str_replace(',', '', $value);                          // 1,234.56
        }

        // Plain decimals only (no «1.23E+15» from a mistyped Excel cell), within decimal(14,2).
        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1 || (float) $value >= 1e12) {
            $planned->fail("«{$this->fields()[$field]['label']}»: μη έγκυρος αριθμός «{$row[$field]}».");

            return null;
        }

        return (float) $value;
    }

    /**
     * The subset of $values that would land on $existing's BLANK fields (null or
     * empty text; `0` too for the listed numeric columns whose blank is a zero
     * default). Never overwrites anything already filled.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $zeroIsBlank
     * @return array<string, mixed>
     */
    protected function blanksToFill(Model $existing, array $values, array $zeroIsBlank = []): array
    {
        $fill = [];
        foreach ($values as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $current = $existing->getAttribute($field);
            $blank = $current === null
                || (is_string($current) && trim($current) === '')
                || (in_array($field, $zeroIsBlank, true) && (float) $current == 0.0);
            if ($blank) {
                $fill[$field] = $value;
            }
        }

        return $fill;
    }

    /** Settle a matched row: FILL when something is blank to fill, else UNCHANGED. */
    protected function settleExisting(PlannedRow $planned, Model $existing, array $fill): void
    {
        $planned->existingId = (int) $existing->getKey();

        if (($fill !== [] || $planned->pending !== []) && $this->mayFill !== null && ! ($this->mayFill)($existing)) {
            $planned->warn('Υπάρχει ήδη — δεν έχεις δικαίωμα επεξεργασίας, οπότε δεν συμπληρώθηκε.');
            $fill = [];
            $planned->pending = [];
        }

        $planned->values = $fill;
        $planned->action = $fill === [] && $planned->pending === [] ? PlannedRow::UNCHANGED : PlannedRow::FILL;
    }
}
