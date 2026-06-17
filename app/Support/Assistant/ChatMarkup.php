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
        $appHost = is_string($appHost) ? $appHost : null;

        // [label](url) — internal links only.
        $safe = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]+)\)/',
            static function (array $m) use ($appHost): string {
                $label = $m[1];        // already escaped
                $url = $m[2];          // already escaped (& → &amp;) — safe in href

                return self::isInternal($url, $appHost)
                    ? '<a href="'.$url.'" class="ai-link">'.$label.'</a>'
                    : $m[0]; // external / ambiguous → leave as inert text
            },
            $safe,
        );

        return new HtmlString($safe);
    }

    /**
     * Is this a link we'll render as a clickable anchor? Only a same-host
     * absolute URL or a relative path qualifies. We DON'T trust parse_url's host
     * alone: browsers normalise a backslash to «/» and treat «@» as userinfo, so
     * `https://evil.com\@app.host/x` parses (in PHP) as host=app.host but a
     * browser navigates to evil.com. So we reject any ambiguous authority
     * (backslash / userinfo / control char) outright before the host compare,
     * and never treat a protocol-relative «//host» as relative.
     */
    private static function isInternal(string $url, ?string $appHost): bool
    {
        // The captured URL was HTML-escaped (e()); decode for parsing.
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);

        // Protocol-relative «//host/…» → an EXTERNAL authority, not a path.
        if (str_starts_with($decoded, '//')) {
            return false;
        }

        // Plain relative path → internal.
        if (str_starts_with($decoded, '/')) {
            return true;
        }

        // Absolute: must be http(s).
        if (! preg_match('#^https?://#i', $decoded)) {
            return false;
        }

        // Isolate the authority (between «://» and the first / ? #) and reject it
        // if it carries a backslash, userinfo «@», or any control char — the
        // exact cases where parse_url and a browser disagree on the real host.
        $afterScheme = substr($decoded, strpos($decoded, '://') + 3);
        $authority = preg_split('~[/?#]~', $afterScheme, 2)[0];
        if (preg_match('~[\\\\@\x00-\x1f\x7f]~', $authority)) {
            return false;
        }

        $host = parse_url($decoded, PHP_URL_HOST);

        // Hosts are case-insensitive.
        return is_string($host) && $appHost !== null && strcasecmp($host, $appHost) === 0;
    }
}
