<?php

namespace App\Services;

use App\Models\Invoice;

/**
 * Renders per-tenant mail subject + body templates with placeholder
 * interpolation — NOT Blade evaluation. Operators can edit templates
 * in the panel without us shipping a code-execution hole.
 *
 * Curated placeholder set:
 *   {tenant_name}      - the issuing company name
 *   {invoice_code}     - e.g. "TPY1234"
 *   {invoice_type}     - the human invoice type label ("Τιμολόγιο")
 *   {issued_at}        - "dd/mm/YYYY HH:MM"
 *   {customer_name}    - snapshot company_name on the invoice
 *   {total}            - gross_total formatted as Greek currency
 *   {net_total}        - net_total formatted as Greek currency
 *   {mark}             - myDATA MARK (empty if not filed)
 *   {verify_url}       - AADE verification URL (empty if not filed)
 *
 * Anything else inside `{...}` is left as-is — so an operator template
 * with stray braces (`{this is a note}`) doesn't crash, it just doesn't
 * interpolate. Strict placeholder set keeps the contract small and
 * audited.
 *
 * Whitespace + HTML escaping are the caller's responsibility — this
 * renderer hands back the substituted string. The Blade layout that
 * wraps the body escapes via {{ }} by default.
 */
class MailTemplateRenderer
{
    public const DEFAULT_SUBJECT_TEMPLATE = '{tenant_name} — {invoice_type} {invoice_code}';

    public const DEFAULT_BODY_TEMPLATE = <<<'TXT'
Αξιότιμε/α πελάτη,

Σας αποστέλλουμε συνημμένα το παραστατικό {invoice_type} {invoice_code} που εκδόθηκε από την επιχείρησή μας {tenant_name}.

Ημερομηνία έκδοσης: {issued_at}
Συνολική αξία: {total}

{mark_section}

Για οποιαδήποτε διευκρίνιση είμαστε στη διάθεσή σας.

Με εκτίμηση,
{tenant_name}
TXT;

    public function renderSubject(Invoice $invoice, ?string $template): string
    {
        $tpl = trim((string) $template) !== '' ? $template : self::DEFAULT_SUBJECT_TEMPLATE;
        $rendered = $this->interpolate($tpl, $this->vars($invoice));
        // Subjects are single-line; collapse any newline an operator
        // accidentally pasted in.
        return trim(preg_replace('/\s+/', ' ', $rendered) ?? '');
    }

    public function renderBody(Invoice $invoice, ?string $template): string
    {
        $tpl = trim((string) $template) !== '' ? $template : self::DEFAULT_BODY_TEMPLATE;
        return $this->interpolate($tpl, $this->vars($invoice));
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function interpolate(string $template, array $vars): string
    {
        // strtr is fastest + safest: no regex, no recursion, no
        // accidental partial matches. Each placeholder is a literal
        // string key in $vars.
        $replacements = [];
        foreach ($vars as $name => $value) {
            $replacements['{'.$name.'}'] = (string) $value;
        }
        return strtr($template, $replacements);
    }

    /**
     * @return array<string, string>
     */
    private function vars(Invoice $invoice): array
    {
        $tenant = $invoice->company;
        $mark = (string) ($invoice->mydata_mark ?? '');
        $url = (string) ($invoice->mydata_url ?? '');

        // mark_section is a precomposed multi-line block so operators
        // can include "{mark_section}" anywhere in their template and
        // get either the full myDATA confirmation paragraph OR
        // nothing (for drafts) — without having to wire conditional
        // logic into the template language.
        $markSection = '';
        if ($mark !== '') {
            $markSection = "Το παραστατικό έχει υποβληθεί στη myDATA της ΑΑΔΕ ".
                "και πιστοποιήθηκε με τον μοναδικό αριθμό MARK: {$mark}.";
            if ($url !== '') {
                $markSection .= "\nΕπαλήθευση: {$url}";
            }
        }

        return [
            'tenant_name'   => (string) ($tenant->name ?? ''),
            'invoice_code'  => (string) ($invoice->invcode ?? ''),
            'invoice_type'  => (string) ($invoice->invoiceType?->name ?? 'Παραστατικό'),
            'issued_at'     => $invoice->issued_at?->format('d/m/Y H:i') ?? '',
            'customer_name' => (string) ($invoice->company_name ?? ''),
            'total'         => $this->money($invoice->payableTotal()),
            'net_total'     => $this->money($invoice->net_total),
            'mark'          => $mark,
            'verify_url'    => $url,
            'mark_section'  => $markSection,
        ];
    }

    private function money(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }
        return number_format((float) $amount, 2, ',', '.').' €';
    }
}
