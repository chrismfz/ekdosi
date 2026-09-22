<?php

namespace App\Services\Reminders;

use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Invoice;
use App\Models\PaymentGatewayConnection;
use App\Models\Scopes\CompanyScope;
use App\Services\MailTemplateRenderer;
use App\Services\Payments\PaymentGatewayRegistry;
use Carbon\CarbonImmutable;

/**
 * The subject + body of one reminder, in the recipient's language. The tenant's
 * per-stage override wins for recipients in the tenant's language; otherwise the
 * translated default (lang/{el,en}/mail.php `reminder.*`). Placeholders:
 *
 *   {tenant_name} {customer_name} {document_kind} {invoice_code} {issued_at}
 *   {due_date} {days_overdue} {balance} {total} {pay_section} {pay_url}
 *
 * {pay_section} is a whole sentence with the portal payment link, or empty when
 * the tenant takes no online payment (so a template never shows a dead link).
 */
final class ReminderMessage
{
    public const PLACEHOLDERS = '{tenant_name}, {customer_name}, {document_kind}, {invoice_code}, {issued_at}, {due_date}, {days_overdue}, {balance}, {total}, {pay_section}, {pay_url}';

    public function __construct(private readonly PaymentGatewayRegistry $gateways) {}

    /**
     * @return array{subject: string, body: string, bodyText: string}
     */
    public function build(
        Invoice $invoice,
        string $stage,
        ?CarbonImmutable $due,
        ?int $daysOverdue,
        float $balance,
        ReminderSettings $settings,
        string $locale,
    ): array {
        $values = $this->values($invoice, $due, $daysOverdue, $balance, $locale);

        $subject = $settings->template($stage, 'subject', $locale) ?? trans("mail.reminder.{$stage}.subject", [], $locale);
        $body = $settings->template($stage, 'body', $locale) ?? trans("mail.reminder.{$stage}.body", [], $locale);

        // Values are data: markdown-neutralised in the HTML body (the mail view
        // e()-escapes the whole string); raw in the subject and the text part.
        $escaped = array_map(
            static fn (string $v, string $k): string => $k === '{pay_url}' || $k === '{pay_section}' ? $v : MailTemplateRenderer::escapeMarkdown($v),
            $values,
            array_keys($values),
        );

        return [
            'subject' => trim(preg_replace('/\s+/', ' ', strtr($subject, $values)) ?? ''),
            'body' => strtr($body, array_combine(array_keys($values), $escaped)),
            'bodyText' => strtr($body, $values),
        ];
    }

    /** @return array<string, string> */
    private function values(Invoice $invoice, ?CarbonImmutable $due, ?int $daysOverdue, float $balance, string $locale): array
    {
        $company = $invoice->company;
        $payUrl = $this->payUrl($company, $invoice);
        $money = static fn (float $v): string => number_format($v, 2, ',', '.').' €';

        return [
            '{tenant_name}' => (string) ($company?->name ?? ''),
            '{customer_name}' => (string) ($invoice->customer?->name ?? ''),
            '{document_kind}' => trans('mail.reminder.kind.'.ReminderPlanner::kindOf($invoice), [], $locale),
            '{invoice_code}' => (string) ($invoice->invcode ?? ''),
            '{issued_at}' => $invoice->issued_at?->format('d/m/Y') ?? '',
            '{due_date}' => $due?->format('d/m/Y') ?? '',
            '{days_overdue}' => (string) max(0, (int) $daysOverdue),
            '{balance}' => $money($balance),
            '{total}' => $money((float) $invoice->payableTotal()),
            '{pay_url}' => $payUrl ?? '',
            '{pay_section}' => $payUrl !== null ? str_replace('{pay_url}', $payUrl, trans('mail.reminder.pay_section', [], $locale)) : '',
        ];
    }

    /**
     * The portal «Πλήρωσε» link for this document — only when the tenant takes
     * online payments AND the customer has a portal login that can use it (an
     * active grant on a non-suspended login; an invited one claims it via the
     * reset link). On the tenant's own portal host when it has one.
     */
    private function payUrl(?Company $company, Invoice $invoice): ?string
    {
        if ($company === null || $invoice->customer_id === null) {
            return null;
        }

        $hasLogin = CustomerUserAccess::query()
            ->active()
            ->where('company_id', $company->getKey())
            ->where('customer_id', $invoice->customer_id)
            ->whereHas('customerUser', fn ($q) => $q->where('status', '!=', CustomerUser::STATUS_SUSPENDED))
            ->exists();
        if (! $hasLogin) {
            return null;
        }

        $chargeable = PaymentGatewayConnection::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->get()
            ->contains(fn (PaymentGatewayConnection $c): bool => $this->gateways->for($c->gateway)->capabilities()->chargeable());
        if (! $chargeable) {
            return null;
        }

        $path = route('portal.payment.create', ['customer' => $invoice->customer_id, 'invoice' => $invoice->getKey()], absolute: false);

        return filled($company->portal_host)
            ? (parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https').'://'.$company->portal_host.$path
            : url($path);
    }
}
