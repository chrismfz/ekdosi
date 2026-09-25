<?php

namespace App\Services\Ergani;

use App\Models\Company;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Εισαγωγή από ΕΡΓΑΝΗ» — reads the employer's CURRENT roster (EX_BASE_05
 * «Στοιχεία τρέχουσας κατάστασης δυναμικού») and creates/updates Employees by ΑΦΜ.
 *
 *  - READ-ONLY towards ΕΡΓΑΝΗ, and ALWAYS from PRODUCTION: the trial environment
 *    has no real employees, and a lookup declares nothing (same e-ΕΦΚΑ creds).
 *  - Data minimisation: EX_BASE_05 also carries ΑΜΚΑ, ID, address, salary… — we
 *    keep ONLY ΑΦΜ, surname, first name, branch (PararthmaAa) and hire date.
 *  - Never deletes / deactivates: someone missing from ΕΡΓΑΝΗ is only reported.
 *  - Existing employees (same ΑΦΜ): names are NOT overwritten (a local spelling
 *    may be deliberate — the diff is shown); branch is synced, hire date filled if empty.
 */
class ErganiEmployeeImporter
{
    public const NEW = 'new';

    public const EXISTS = 'exists';

    public const DELETED = 'deleted';

    /**
     * @return list<array{afm: string, last_name: string, first_name: string, branch: int, hired_at: ?string, multi_branch: bool}>
     */
    public function fetch(Company $company): array
    {
        $production = clone $company;
        $production->ergani_mode = 'production';   // in memory only — never saved

        $rows = (array) data_get((new ErganiClient($production))->service('EX_BASE_05'), 'EX_BASE_05.Cur', []);
        if (isset($rows['afm'])) {
            $rows = [$rows];   // a single employee may come back as an object, not a list
        }

        $out = [];
        foreach ($rows as $row) {
            $afm = trim((string) data_get($row, 'afm'));
            $branch = (int) data_get($row, 'PararthmaAa', 0);
            if (! preg_match('/^\d{9}$/', $afm)) {
                continue;
            }
            if ($branch < 0 || $branch > 255) {
                Log::warning('ΕΡΓΑΝΗ import: branch out of range, row skipped', ['company' => $company->getKey(), 'branch' => $branch]);

                continue;
            }
            if (isset($out[$afm])) {
                // Same person in two παραρτήματα: keep the first, flag it — the operator picks.
                $out[$afm]['multi_branch'] = true;

                continue;
            }
            $out[$afm] = [
                'afm' => $afm,
                'last_name' => self::title((string) data_get($row, 'Eponimo')),
                'first_name' => self::title((string) data_get($row, 'Onoma')),
                'branch' => $branch,
                'hired_at' => self::date(data_get($row, 'DateFrom')),
                'multi_branch' => false,
            ];
        }

        return array_values($out);
    }

    /**
     * Compare with the local roster.
     *
     * @param  list<array{afm: string, last_name: string, first_name: string, branch: int, hired_at: ?string}>  $rows
     * @return array{rows: list<array{afm: string, last_name: string, first_name: string, branch: int, hired_at: ?string, status: string, local_name: ?string}>, missing: list<string>}
     */
    public function plan(Company $company, array $rows): array
    {
        $local = Employee::query()->withTrashed()->where('company_id', $company->getKey())
            ->whereNotNull('afm')->get()->keyBy('afm');

        $planned = [];
        foreach ($rows as $row) {
            $e = $local->get($row['afm']);
            $planned[] = $row + [
                'status' => $e === null ? self::NEW : ($e->trashed() ? self::DELETED : self::EXISTS),
                'local_name' => $e?->full_name,
                'local_inactive' => $e !== null && ! $e->trashed() && ! $e->is_active,
            ];
        }

        $afms = array_column($rows, 'afm');
        $missing = Employee::query()->where('company_id', $company->getKey())->where('is_active', true)
            ->get()
            ->filter(fn (Employee $e): bool => $e->afm === null || ! in_array($e->afm, $afms, true))
            ->map(fn (Employee $e): string => $e->full_name)
            ->values()->all();

        return ['rows' => $planned, 'missing' => $missing];
    }

    /**
     * Apply the SELECTED ΑΦΜ from a FRESH fetch (never from browser-supplied rows).
     *
     * @param  list<string>  $selectedAfms
     * @param  list<array<string, mixed>>|null  $freshRows  rows fetched in THIS request (server-side), else re-read
     * @return array{created: int, updated: int, skipped: int}
     */
    public function apply(Company $company, array $selectedAfms, ?array $freshRows = null): array
    {
        $rows = collect($freshRows ?? $this->fetch($company))->keyBy('afm');
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($company, $selectedAfms, $rows, &$result): void {
            foreach (array_unique($selectedAfms) as $afm) {
                $row = $rows->get($afm);
                if ($row === null) {
                    $result['skipped']++;

                    continue;
                }
                $e = Employee::query()->withTrashed()->where('company_id', $company->getKey())
                    ->where('afm', $afm)->lockForUpdate()->first();

                if ($e === null) {
                    Employee::create([
                        'company_id' => $company->getKey(),
                        'afm' => $afm,
                        'last_name' => $row['last_name'],
                        'first_name' => $row['first_name'],
                        'ergani_branch' => $row['branch'],
                        'hired_at' => $row['hired_at'],
                        'is_active' => true,
                    ]);
                    $result['created']++;
                } elseif ($e->trashed()) {
                    $result['skipped']++;   // deleted locally on purpose — restore by hand
                } else {
                    $e->ergani_branch = $row['branch'];
                    $e->hired_at ??= $row['hired_at'];
                    if ($e->isDirty()) {
                        $e->save();
                        $result['updated']++;
                    } else {
                        $result['skipped']++;   // already up to date
                    }
                }
            }
        });

        return $result;
    }

    /** «ΑΝΤΩΝΙΟΥ» → «Αντωνιου» (ΕΡΓΑΝΗ has no accents; we upper-case again when declaring). */
    private static function title(string $name): string
    {
        $t = mb_convert_case(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), MB_CASE_TITLE, 'UTF-8');

        // Final sigma at word end.
        return preg_replace('/σ\b/u', 'ς', $t) ?? $t;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->setTimezone('Europe/Athens')->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
