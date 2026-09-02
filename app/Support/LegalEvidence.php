<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What LEGAL FILING EVIDENCE a tenant holds — the proof that documents were
 * actually transmitted (MYD-025).
 *
 * A MARK is the evidence: AADE issued it, AADE still has it, and it is what ties
 * a local row to a remote tax record. It survives the document being cancelled —
 * a cancellation is itself a filing.
 *
 * This exists because destroying a tenant's data was SILENT: the company-delete
 * confirmation said nothing about what it was about to erase, and the wiper's
 * `--force` gate counted only invoices with `mydata_state = VALID`, so it missed
 * every CANCELLED invoice, every delivery note and every expense classification.
 * Deletion is a legitimate operation — a tenant that outgrows a shared host is
 * exported, restored elsewhere and then removed — so the answer is not to forbid
 * it. It is to make it measured, deliberate and recoverable.
 *
 * ONE definition, shared by the wiper gate and the delete confirmation, so the
 * number the operator is warned about is the number the guard counts.
 *
 * Deliberately the query builder with an explicit `company_id`, never Eloquent:
 * these models carry `CompanyScope`, which is a documented no-op outside a
 * request and would otherwise silently zero the count for a super_admin acting
 * on a company other than the selected tenant (the MYD-022 lesson).
 */
final class LegalEvidence
{
    private function __construct(
        public readonly int $invoiceMarks,
        public readonly int $deliveryMarks,
        public readonly int $expenseMarks,
        public readonly int $filedInvoices,
        public readonly int $filedDeliveryNotes,
        public readonly ?string $firstFiledOn,
        public readonly ?string $lastFiledOn,
    ) {}

    public static function for(Company $company): self
    {
        $id = (int) $company->getKey();

        $dates = [];
        $marks = [];

        foreach (['mydata_marks', 'delivery_marks', 'expense_marks'] as $table) {
            [$marks[$table], $range] = self::countMarks($table, $id);

            foreach ($range as $date) {
                if ($date !== null) {
                    $dates[] = $date;
                }
            }
        }

        sort($dates);

        return new self(
            invoiceMarks: $marks['mydata_marks'],
            deliveryMarks: $marks['delivery_marks'],
            expenseMarks: $marks['expense_marks'],
            filedInvoices: self::countFiled('invoices', $id),
            filedDeliveryNotes: self::countFiled('delivery_notes', $id),
            firstFiledOn: $dates[0] ?? null,
            lastFiledOn: $dates === [] ? null : $dates[count($dates) - 1],
        );
    }

    public function totalMarks(): int
    {
        return $this->invoiceMarks + $this->deliveryMarks + $this->expenseMarks;
    }

    /** Has anything ever been filed under this tenant? */
    public function exists(): bool
    {
        return $this->totalMarks() > 0
            || $this->filedInvoices > 0
            || $this->filedDeliveryNotes > 0;
    }

    /**
     * Operator-facing summary — the sentence that turns a silent destructive
     * action into a measured one. Greek, because it is read in a confirmation
     * dialog, not a log.
     */
    public function describe(): string
    {
        if (! $this->exists()) {
            return 'Δεν έχει υποβληθεί κανένα παραστατικό στην ΑΑΔΕ για αυτή την εταιρεία.';
        }

        $parts = [];

        if ($this->filedInvoices > 0) {
            $parts[] = self::plural($this->filedInvoices, 'υποβληθέν τιμολόγιο', 'υποβληθέντα τιμολόγια');
        }
        if ($this->filedDeliveryNotes > 0) {
            $parts[] = self::plural($this->filedDeliveryNotes, 'δελτίο αποστολής', 'δελτία αποστολής');
        }
        if ($this->expenseMarks > 0) {
            $parts[] = self::plural($this->expenseMarks, 'χαρακτηρισμός εξόδου', 'χαρακτηρισμοί εξόδων');
        }

        $summary = self::plural($this->totalMarks(), 'ΜΑΡΚ', 'ΜΑΡΚ');

        if ($parts !== []) {
            $summary .= ' ('.implode(' · ', $parts).')';
        }

        if ($this->firstFiledOn !== null && $this->lastFiledOn !== null) {
            $from = self::humanDate($this->firstFiledOn);
            $to = self::humanDate($this->lastFiledOn);
            $summary .= $from === $to ? ", {$from}" : ", {$from} – {$to}";
        }

        return $summary;
    }

    /**
     * Real MARKs only, plus the date range they span.
     *
     * A mark row with a NULL or empty `mark` is a forensic record of an attempt —
     * DRY_RUN, REJECTED, PROVIDER_FAILED — not proof that anything was filed.
     * Counting those would warn about test runs and block a clean-slate re-import
     * over a dry run, which is exactly the workflow the wiper exists to serve.
     *
     * @return array{0: int, 1: array{0: ?string, 1: ?string}}
     */
    private static function countMarks(string $table, int $companyId): array
    {
        if (! Schema::hasTable($table)) {
            return [0, [null, null]];
        }

        $query = DB::table($table)
            ->where('company_id', $companyId)
            ->whereNotNull('mark')
            ->where('mark', '!=', '');

        $count = (clone $query)->count();

        if ($count === 0 || ! Schema::hasColumn($table, 'mark_date')) {
            return [$count, [null, null]];
        }

        return [$count, [
            (clone $query)->min('mark_date'),
            (clone $query)->max('mark_date'),
        ]];
    }

    /**
     * Documents whose myDATA state says they reached AADE. CANCELLED counts:
     * the cancellation is itself a filing, and the remote record of both the
     * issue and its cancellation outlives anything we delete locally.
     */
    private static function countFiled(string $table, int $companyId): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'mydata_state')) {
            return 0;
        }

        return DB::table($table)
            ->where('company_id', $companyId)
            ->whereIn('mydata_state', ['VALID', 'CANCELLED'])
            ->count();
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }

    private static function humanDate(string $date): string
    {
        // The column is a DATE, but a driver can hand back a full datetime.
        return date('d/m/Y', strtotime($date) ?: time());
    }
}
