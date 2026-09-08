<?php

namespace App\Support;

/**
 * A small, dependency-free HTML → plain-text converter for inbound email bodies
 * (Πυλώνας E, Phase 4 follow-up). Modern clients often send HTML-only mail; without
 * this the raw markup ended up as the ticket body. Not a full renderer — just enough
 * to make an HTML email readable as text: drop script/style, turn block boundaries
 * into line breaks, strip the remaining tags, decode entities, and tidy whitespace.
 */
class HtmlToText
{
    public static function convert(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        // Every preg_replace keeps the previous value on a null return (e.g. a
        // backtrack-limit hit on a very large body) rather than casting null → ''
        // and silently emptying the whole message — worst case that one substitution
        // is skipped (its markup then strips to inline text) but the body survives.
        // Drop non-content elements entirely (with their content).
        $html = preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;

        // Line breaks + list bullets from common block/break tags. Paragraphs and
        // blockquotes get a blank line; lighter blocks get a single newline; table
        // cells get a space so columns don't run together.
        $html = preg_replace('#</(p|blockquote)>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(div|tr|h[1-6]|li)>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(td|th)>#i', ' ', $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', '• ', $html) ?? $html;

        // Strip every remaining tag, then decode entities (&amp; &nbsp; …).
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Tidy: normalise newlines, trim trailing spaces per line, collapse 3+ blank
        // lines to one, and trim the whole thing.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
