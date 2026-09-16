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
 *
 * DOC-8: the invoice mail is a MARKDOWN mailable, so the rendered body is
 * parsed by CommonMark. `e()` in the Blade slot escapes HTML but NOT markdown
 * syntax — so an interpolated value like a customer name «[x](http://evil)»
 * would become a live link/image. `renderBody()` therefore backslash-escapes
 * ASCII punctuation in the interpolated VALUES (not the operator's template,
 * which may carry intentional markdown, and not the AADE url / mark_section,
 * which must stay functional). The SUBJECT is plain text → never escaped.
 */
class MailTemplateRenderer
{
    /** Body placeholders left RAW (functional URL / our own composed block). */
    private const BODY_RAW_PLACEHOLDERS = ['verify_url', 'mark_section'];

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
        // DOC-8: escape markdown in the interpolated values — the body is
        // rendered through CommonMark, so an un-escaped «[x](url)» in e.g. the
        // customer name would become a live link.
        return $this->interpolate($tpl, $this->vars($invoice), escapeMarkdown: true);
    }

    /**
     * The SAME body, but WITHOUT markdown escaping — for the plain-text MIME
     * part (DOC-8 Finding A). The text part is not parsed by CommonMark, so the
     * backslash-escaping renderBody() adds for the HTML part would show up as
     * literal `\.`/`\,` in text-only clients. The injection risk that DOC-8
     * closes is HTML-only (a live link/image), so plain text needs no escaping.
     */
    public function renderBodyPlain(Invoice $invoice, ?string $template): string
    {
        $tpl = trim((string) $template) !== '' ? $template : self::DEFAULT_BODY_TEMPLATE;
        return $this->interpolate($tpl, $this->vars($invoice));
    }

    /**
     * Backslash-escape CommonMark ASCII punctuation so an interpolated value
     * renders LITERALLY in a markdown body (DOC-8). `\x` renders as `x` for
     * every punctuation char, so a normal name/number is displayed unchanged.
     */
    public static function escapeMarkdown(string $value): string
    {
        return preg_replace('/[!"#$%&\'()*+,\-.\/:;<=>?@\[\\\\\]^_`{|}~]/', '\\\\$0', $value) ?? $value;
    }

    /**
     * Wrap bare http(s) URLs in an ALREADY HTML-escaped string with a clickable
     * `<a href>`. The invoice mail's HTML body is emitted via `{!! nl2br(e($body)) !!}`
     * — so the body is HTML-escaped, and although this IS a markdown mailable
     * (CommonMark runs on the view), CommonMark does not autolink a BARE
     * `http://…` URL — so the AADE verification URL (from `{verify_url}`/
     * `{mark_section}`) would otherwise render as dead plain text. We link the
     * escaped text on purpose:
     *   - the URL run carries NO raw `"`/`<`/`>` (they are already `&quot;`/`&lt;`/
     *     `&gt;` entities, which stay INSIDE the attribute value and cannot break
     *     out of `href="…"`), and `&` is the `&amp;` entity — valid in an href;
     *   - ONLY `http`/`https` are linked (never `javascript:`/`data:`), and since
     *     the body is escaped FIRST, operator-typed markup can never smuggle a tag
     *     or a scheme through this path — the DOC-8 boundary is preserved.
     * A sentence-final `.,!?` is left OUT of the link (but never `;`/`)`, which can
     * belong to an entity like `&amp;` or to the URL itself).
     */
    public static function autolink(string $escapedHtml): string
    {
        // `(?:&amp;|[^\s<&])+` — consume normal URL chars, KEEP `&amp;` (a real
        // query `&`), but STOP at any other entity (`&quot;`/`&lt;`/`&gt;`/`&#039;`
        // from escaped operator input) so the link never over-consumes escaped
        // markup that happens to follow a URL.
        return preg_replace_callback(
            '#\bhttps?://(?:&amp;|[^\s<&])+#i',
            static function (array $m): string {
                $url = $m[0];
                $trail = '';
                while ($url !== '' && str_contains('.,!?', substr($url, -1))) {
                    $trail = substr($url, -1).$trail;
                    $url = substr($url, 0, -1);
                }

                return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.$url.'</a>'.$trail;
            },
            $escapedHtml,
        ) ?? $escapedHtml;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function interpolate(string $template, array $vars, bool $escapeMarkdown = false): string
    {
        // strtr is fastest + safest: no regex, no recursion, no
        // accidental partial matches. Each placeholder is a literal
        // string key in $vars.
        $replacements = [];
        foreach ($vars as $name => $value) {
            $value = (string) $value;
            if ($escapeMarkdown && ! in_array($name, self::BODY_RAW_PLACEHOLDERS, true)) {
                $value = self::escapeMarkdown($value);
            }
            $replacements['{'.$name.'}'] = $value;
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
