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

        // Drop non-content elements entirely (with their content).
        $html = (string) preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', $html);

        // Line breaks + list bullets from common block/break tags. Paragraphs and
        // blockquotes get a blank line; lighter blocks get a single newline.
        $html = (string) preg_replace('#</(p|blockquote)>#i', "\n\n", $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(div|tr|h[1-6]|li)>#i', "\n", $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', '• ', $html);

        // Strip every remaining tag, then decode entities (&amp; &nbsp; …).
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Tidy: normalise newlines, trim trailing spaces per line, collapse 3+ blank
        // lines to one, and trim the whole thing.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
