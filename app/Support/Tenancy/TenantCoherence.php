<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
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
     * The relation list is exactly what `AadeInvoiceDocument` and the provider
     * documents read: the counterpart (customer), the invoice type (myDATA type,
     * series, income classification), the payment method (myDATA payment type) and
     * the lines (net/VAT/classification). A relation that is simply absent is not a
     * coherence failure — the payload builders have their own required-field
     * errors, and duplicating them here would just produce a worse message.
     */
    public static function assertInvoice(Company $tenant, Invoice $invoice): void
    {
        self::assertOwned($tenant, $invoice, 'invoice', (string) $invoice->invcode);

        $label = (string) $invoice->invcode;

        self::assertOwned($tenant, $invoice->customer, 'customer', $label);
        self::assertOwned($tenant, $invoice->invoiceType, 'invoice type', $label);
        self::assertOwned($tenant, $invoice->paymentMethod, 'payment method', $label);

        // relationLoaded, not the accessor: touching ->lines here would issue a
        // query on every submit just to re-check what the payload is about to load
        // anyway. When they ARE loaded (every real filing path loads them), check
        // them; the line-level company_id is also enforced by the schema.
        if ($invoice->relationLoaded('lines')) {
            foreach ($invoice->lines as $line) {
                self::assertOwned($tenant, $line, 'invoice line', $label);
            }
        }
    }

    /**
     * Assert that $note — and every relation whose values reach the 9.x payload —
     * belongs to $tenant.
     */
    public static function assertDeliveryNote(Company $tenant, DeliveryNote $note): void
    {
        self::assertOwned($tenant, $note, 'delivery note', (string) $note->invcode);

        $label = (string) $note->invcode;

        self::assertOwned($tenant, $note->customer, 'recipient', $label);
        self::assertOwned($tenant, $note->deliveryType, 'delivery type', $label);

        if ($note->relationLoaded('lines')) {
            foreach ($note->lines as $line) {
                self::assertOwned($tenant, $line, 'delivery note line', $label);
            }
        }
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
