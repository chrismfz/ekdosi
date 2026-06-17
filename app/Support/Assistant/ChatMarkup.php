<?php

namespace App\Support\Assistant;

use Illuminate\Support\HtmlString;

/**
 * Minimal, SAFE renderer for the AI «Βοηθός» assistant turns: HTML-escape
 * everything first, then re-introduce only **bold** and markdown links — and a
 * link is rendered as an anchor ONLY when its URL is same-origin (the app host)
 * or a relative path. An external URL (e.g. one a prompt-injected customer name
 * tried to smuggle) is left as inert text, so the assistant can never produce a
 * clickable off-site/phishing link.
 */
class ChatMarkup
{
    public static function render(string $text): HtmlString
    {
        $safe = e($text);

        // **bold**
        $safe = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        // [label](url) — internal links only.
        $safe = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]+)\)/',
            static function (array $m) use ($appHost): string {
                $label = $m[1];        // already escaped
                $url = $m[2];          // already escaped (& → &amp;) — safe in href
                $host = parse_url(html_entity_decode($url), PHP_URL_HOST);
                $internal = $host === null || $host === $appHost;

                return $internal
                    ? '<a href="'.$url.'" class="ai-link">'.$label.'</a>'
                    : $m[0]; // external → leave as inert text
            },
            $safe,
        );

        return new HtmlString($safe);
    }
}
