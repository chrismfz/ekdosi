<?php

namespace App\Services\Portability;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Wipes a tenant's TRANSACTIONAL data while keeping the company row + all
 * settings + setup/lookups — the safe «clean slate before a Firebird re-import»
 * (FEATURES.md). Lives in the «Αντίγραφα» menu next to
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
        'return_invoice_extras', 'invoice_mail_log', 'whmcs_invoice_log', 'mydata_marks', 'payments', 'invoice_lines',
        'quote_mail_logs', 'quote_lines', 'expense_marks', 'expense_lines',
        'delivery_marks', 'delivery_note_lines', 'stock_movements', 'service_contracts',
        'pending_whmcs_invoices', 'delivery_notes', 'quotes', 'expenses', 'invoices',
        'activity_log', 'notes', 'attachments',
    ];

    /** Customers/suppliers/products — wiped unless --keep-parties. */
    public const PARTY_TABLES = [
        'lead_activities', 'leads',
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
     * @param  bool  $force  required when invoices are filed at AADE (VALID).
     * @return array<string,int> per-table deleted counts
     */
    public function wipe(Company $company, bool $keepParties, bool $resetCounter, bool $force = false): array
    {
        // Safe-by-default: even a programmatic caller can't blow away docs that
        // are live at AADE without opting in (the command/UI also guard).
        if (! $force && $this->filedAtAadeCount($company) > 0) {
            throw new RuntimeException('Υπάρχουν παραστατικά υποβλημένα στην ΑΑΔΕ (VALID)· χρειάζεται force.');
        }

        $deleted = [];

        Schema::withoutForeignKeyConstraints(function () use ($company, $keepParties, $resetCounter, &$deleted): void {
            DB::transaction(function () use ($company, $keepParties, $resetCounter, &$deleted): void {
                // Morph-pivot tag links (no company_id) for the subjects we wipe.
                $this->wipeTaggables($company->id, $keepParties);

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
     * Drop `taggables` rows (the HasTags morph pivot — no company_id) linking
     * this tenant's tags to the subjects being wiped: always Invoice, plus
     * Customer/Product/Lead unless --keep-parties. The tag vocabulary itself is kept
     * (setup); only the links to deleted records die.
     */
    private function wipeTaggables(int $companyId, bool $keepParties): void
    {
        if (! Schema::hasTable('taggables')) {
            return;
        }

        $morphTypes = [(new Invoice)->getMorphClass()];
        if (! $keepParties) {
            $morphTypes[] = (new Customer)->getMorphClass();
            $morphTypes[] = (new Product)->getMorphClass();
            $morphTypes[] = (new Lead)->getMorphClass();
        }

        DB::table('taggables')
            ->whereIn('tag_id', DB::table('tags')->where('company_id', $companyId)->select('id'))
            ->whereIn('taggable_type', $morphTypes)
            ->delete();
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
