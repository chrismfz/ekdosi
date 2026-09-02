<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Fail-closed assertion that a document and every legal relation on it belong to
 * the tenant we are about to file as (MYD-022).
 *
 * The filing services receive a `Company $tenant` INDEPENDENTLY of the document:
 * the issuer ΑΦΜ, branch, myDATA credentials and provider contract come from the
 * tenant, while the counterpart, type, lines and payment method come from the
 * document. Nothing checked that the two agree. A programming error or a crafted
 * service/API/CLI call could therefore transmit tenant B's commercial data under
 * tenant A's ΑΦΜ and credentials — a false filing AND a cross-tenant
 * confidentiality incident, both of which are legally irreversible once AADE has
 * issued a MARK.
 *
 * The Filament panel's tenant scoping normally makes this unreachable, but scoping
 * is an ambient convenience, not a boundary: `CompanyScope` is a documented no-op
 * outside a request (CLI, queue, webhooks), which is exactly where automation runs.
 * So this asserts on the DATA, never on the ambient context.
 *
 * Deliberately BEFORE payload construction, audit writes and any outbound request:
 * a mismatch must not reach the wire, and must not leave a half-written audit trail
 * suggesting it did.
 */
final class TenantCoherence
{
    /**
     * Assert that $invoice — and every relation whose values reach the payload —
     * belongs to $tenant.
     *
     * The relation list is what `AadeInvoiceDocument` and the provider documents
     * read: the counterpart (customer), the invoice type (myDATA type, series,
     * income classification), the payment method (myDATA payment type), the lines
     * (net/VAT) and — via `lines.product.productCategory` — the per-line E3
     * classification override. A relation that is simply absent is not a coherence
     * failure: the payload builders have their own required-field errors, and
     * duplicating them here would just produce a worse message.
     */
    public static function assertInvoice(Company $tenant, Invoice $invoice): void
    {
        self::assertOwned($tenant, $invoice, 'invoice', (string) $invoice->invcode);

        $label = (string) $invoice->invcode;

        self::assertRelation($tenant, $invoice, 'customer', 'customer', $label);
        self::assertRelation($tenant, $invoice, 'invoiceType', 'invoice type', $label);
        self::assertRelation($tenant, $invoice, 'paymentMethod', 'payment method', $label);

        self::assertLines($tenant, $invoice, 'invoice line', $label);
    }

    /**
     * The products referenced by a document's lines, and their categories.
     *
     * `AadeInvoiceDocument` resolves the per-line E3 income classification through
     * `lines.product.productCategory`, so a foreign product silently files another
     * tenant's classification under this tenant's ΑΦΜ. ONE query for all distinct
     * product ids (and one for their categories), not one per line — the check must
     * not turn a submit into N round-trips.
     *
     * @param  array<int, int|string|null>  $productIds
     */
    private static function assertLineProducts(Company $tenant, array $productIds, string $documentLabel): void
    {
        $ids = array_values(array_unique(array_filter($productIds)));

        if ($ids === []) {
            return;
        }

        $products = Product::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $ids)
            ->get(['id', 'company_id', 'product_category_id']);

        foreach ($products as $product) {
            self::assertOwned($tenant, $product, 'line product', $documentLabel);
        }

        $categoryIds = array_values(array_unique(array_filter($products->pluck('product_category_id')->all())));

        if ($categoryIds === []) {
            return;
        }

        ProductCategory::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $categoryIds)
            ->get(['id', 'company_id'])
            ->each(fn (ProductCategory $category) => self::assertOwned(
                $tenant, $category, 'product category', $documentLabel,
            ));
    }

    /**
     * Assert that $note — and every relation whose values reach the 9.x payload —
     * belongs to $tenant.
     */
    public static function assertDeliveryNote(Company $tenant, DeliveryNote $note): void
    {
        self::assertOwned($tenant, $note, 'delivery note', (string) $note->invcode);

        $label = (string) $note->invcode;

        self::assertRelation($tenant, $note, 'customer', 'recipient', $label);
        self::assertRelation($tenant, $note, 'deliveryType', 'delivery type', $label);

        self::assertLines($tenant, $note, 'delivery note line', $label);
    }

    /**
     * The document's lines, and the products/categories they reference.
     *
     * Read WITHOUT the tenant scope, for the same reason assertRelation() does: a
     * scoped read of a foreign line returns NOTHING, so the check would pass on an
     * empty set — silently inert exactly where an operator sits. Two earlier
     * versions of this branch were inert in the panel for two different reasons
     * (gated on relationLoaded(), then scoped by CompanyScope); reading past the
     * scope is what actually makes it fire.
     *
     * Also deliberately NOT the loaded relation: an already-loaded `lines` was
     * loaded THROUGH the scope, so trusting it would reintroduce the same hole.
     */
    private static function assertLines(Company $tenant, Model $document, string $what, string $documentLabel): void
    {
        $lines = $document->lines()
            ->withoutGlobalScope(CompanyScope::class)
            ->get(['id', 'company_id', 'product_id']);

        foreach ($lines as $line) {
            self::assertOwned($tenant, $line, $what, $documentLabel);
        }

        self::assertLineProducts($tenant, $lines->pluck('product_id')->all(), $documentLabel);
    }

    /**
     * Resolve one relation WITHOUT the tenant scope, then check it.
     *
     * The scope is why this needs its own method. `CompanyScope` filters a lazy
     * load by the ambient tenant, so inside the panel a cross-tenant `customer_id`
     * resolves to NULL rather than to the foreign row — and a null relation is
     * legitimately allowed (an invoice may simply have no payment method). The
     * relation half of this guard therefore fired from CLI and queue but stayed
     * silent in the panel: the exact opposite of the context-independence this
     * class promises. Reading past the scope makes the foreign row visible so it
     * can be refused.
     *
     * A foreign key pointing at a row that does not exist at all still resolves to
     * null and still passes — that is a broken FK, not a tenant leak, and the
     * payload builders report it far better than a coherence error would.
     */
    private static function assertRelation(Company $tenant, Model $document, string $relation, string $what, string $documentLabel): void
    {
        $related = $document->{$relation}()
            ->withoutGlobalScope(CompanyScope::class)
            ->first();

        self::assertOwned($tenant, $related, $what, $documentLabel);
    }

    /**
     * The single check. A null relation passes (see assertInvoice); anything
     * present must carry the tenant's own company_id.
     */
    private static function assertOwned(Company $tenant, ?Model $model, string $what, string $documentLabel): void
    {
        if ($model === null) {
            return;
        }

        $tenantId = $tenant->getKey();
        $modelCompanyId = $model->getAttribute('company_id');

        if ($tenantId === null) {
            throw new RuntimeException(
                'Refusing to file: the issuing company has no id. '
                .'A tenant must be persisted before anything can be filed under it.'
            );
        }

        // Loose (==) is wrong here and int-vs-string is a real shape: a company_id
        // read back from a query builder can be a string on some drivers. Compare
        // as ints so '3' and 3 agree, but null never equals 0.
        if ($modelCompanyId === null || (int) $modelCompanyId !== (int) $tenantId) {
            throw new RuntimeException(sprintf(
                'Tenant mismatch on %s: the %s belongs to company %s but the filing '
                .'is being made as company %s (%s). Refusing to file — this would '
                .'transmit one tenant\'s data under another tenant\'s ΑΦΜ and credentials.',
                $documentLabel !== '' ? $documentLabel : '(unnumbered document)',
                $what,
                $modelCompanyId === null ? 'NULL' : (string) $modelCompanyId,
                (string) $tenantId,
                (string) ($tenant->slug ?? $tenant->name ?? '?'),
            ));
        }
    }
}
