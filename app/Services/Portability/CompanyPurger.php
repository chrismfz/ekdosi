<?php

namespace App\Services\Portability;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Makes `$company->delete()` actually work on MariaDB.
 *
 * Deleting a tenant relies on ON DELETE CASCADE from `companies`. But a few
 * tenant tables point at OTHER tenant tables with ON DELETE RESTRICT — on
 * purpose: an invoice must never lose its type, a product its VAT category. In
 * one cascading DELETE, InnoDB walks the children in its own order and checks
 * each FK row by row, so it can reach `product_categories` while the products
 * pointing at it still exist → 1451 «Cannot delete or update a parent row».
 * (sqlite checks FKs at end of statement, so the test suite never saw it.)
 *
 * Loosening those FKs would trade a legal guarantee for a delete button. So
 * instead the RESTRICT-side tables are emptied first, in an order where each
 * one goes before whatever it restricts; the company delete then cascades
 * through a graph with no RESTRICT edge left in it. Runs from
 * Company::delete(), inside the transaction it opens.
 *
 * Not CompanyDataWiper: that one switches FK checks OFF, which on MariaDB also
 * switches cascades off — fine for its explicit table list, wrong here where
 * the cascade is what removes the other ~80 tables.
 *
 * The list is guarded by CompanyPurgeOrderTest, which rebuilds the FK graph
 * from the schema: a new RESTRICT FK between two tenant tables fails CI until
 * its child table is added here.
 */
class CompanyPurger
{
    /**
     * Child sides of every RESTRICT FK between two company-cascaded tables,
     * each listed BEFORE any table whose delete would reach its parent:
     * pending_whmcs_invoices → invoices · domains → customers, domain_tlds ·
     * service_contracts → customers · delivery_notes, invoices → invoice_types ·
     * products → product_categories, vat_categories.
     */
    public const RESTRICTED_FIRST = [
        'pending_whmcs_invoices',
        'domains',
        'service_contracts',
        'delivery_notes',
        'invoices',
        'products',
    ];

    public function clearRestrictedChildren(Company $company): void
    {
        foreach (self::RESTRICTED_FIRST as $table) {
            DB::table($table)->where('company_id', $company->getKey())->delete();
        }
    }
}
