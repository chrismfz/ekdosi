<?php

namespace App\Services\Portability;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wipes a tenant's TRANSACTIONAL data while keeping the company row + all
 * settings + setup/lookups — the safe «clean slate before a Firebird re-import»
 * (docs/company-portability-plan.md). Lives in the «Αντίγραφα» menu next to
 * export/import precisely so a wipe is never reached without a backup at hand.
 *
 * FK ordering is sidestepped with Schema::withoutForeignKeyConstraints (portable
 * across MariaDB/sqlite); every delete is scoped by company_id. `--keep-parties`
 * preserves customers/suppliers/products; `--reset-counter` rolls each invoice
 * type's ΑΑ counter back to 1 (a Firebird import would otherwise set it to
 * max(legacy, current) anyway).
 */
class CompanyDataWiper
{
    /** Documents + their children + audit/inbox — always wiped. */
    public const DOC_TABLES = [
        'return_invoice_extras', 'invoice_mail_log', 'mydata_marks', 'payments', 'invoice_lines',
        'quote_mail_logs', 'quote_lines', 'expense_marks', 'expense_lines',
        'delivery_marks', 'delivery_note_lines', 'stock_movements', 'service_contracts',
        'pending_whmcs_invoices', 'delivery_notes', 'quotes', 'expenses', 'invoices',
        'activity_log', 'notes', 'attachments',
    ];

    /** Customers/suppliers/products — wiped unless --keep-parties. */
    public const PARTY_TABLES = [
        'customer_contacts', 'customers', 'suppliers',
        'product_billing_prices', 'product_price_tiers', 'products',
    ];

    /**
     * Per-table row counts that WOULD be deleted (non-zero only).
     *
     * @return array<string,int>
     */
    public function plan(Company $company, bool $keepParties): array
    {
        $counts = [];
        foreach ($this->tables($keepParties) as $table) {
            $n = DB::table($table)->where('company_id', $company->id)->count();
            if ($n > 0) {
                $counts[$table] = $n;
            }
        }

        return $counts;
    }

    /** Invoices already filed at AADE (mydata_state=VALID) — the --force gate. */
    public function filedAtAadeCount(Company $company): int
    {
        return DB::table('invoices')
            ->where('company_id', $company->id)
            ->where('mydata_state', 'VALID')
            ->count();
    }

    /**
     * @return array<string,int> per-table deleted counts
     */
    public function wipe(Company $company, bool $keepParties, bool $resetCounter): array
    {
        $deleted = [];

        Schema::withoutForeignKeyConstraints(function () use ($company, $keepParties, $resetCounter, &$deleted): void {
            DB::transaction(function () use ($company, $keepParties, $resetCounter, &$deleted): void {
                foreach ($this->tables($keepParties) as $table) {
                    $n = DB::table($table)->where('company_id', $company->id)->delete();
                    if ($n > 0) {
                        $deleted[$table] = $n;
                    }
                }

                if ($resetCounter) {
                    DB::table('invoice_types')->where('company_id', $company->id)->update(['invcount' => 1]);
                }
            });
        });

        return $deleted;
    }

    /**
     * @return list<string> existing, company-scoped tables in this run's scope
     */
    private function tables(bool $keepParties): array
    {
        $tables = $keepParties
            ? self::DOC_TABLES
            : array_merge(self::DOC_TABLES, self::PARTY_TABLES);

        return array_values(array_filter(
            $tables,
            fn (string $t): bool => Schema::hasTable($t) && Schema::hasColumn($t, 'company_id'),
        ));
    }
}
