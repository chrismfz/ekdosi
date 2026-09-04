<?php

namespace WHMCS\Module\Addon\EkdosiBridge\Client;

use WHMCS\Database\Capsule;

/**
 * T-2 (timologia v2): the hide/reveal gate for the client-area page.
 *
 * Two admin config knobs (set on the addon's config page, read from
 * tbladdonmodules):
 *   - show_client_v2  (on/off) — master switch. Default OFF, so customers see
 *     NOTHING until the operator flips it (e.g. for an off-hours test, then
 *     flips it back).
 *   - v2_pilot_clients (csv of WHMCS client ids) — when non-empty, ONLY those
 *     clients see/use v2. Lets one real reseller pilot it while everyone else
 *     sees nothing.
 *
 * The SAME gate guards both the navbar menu item (hooks.php) AND the page
 * handler itself (ekdosi_bridge_clientarea) — so a customer can't reach a
 * hidden page by guessing the URL.
 */
class Gate
{
    public static function visibleTo(int $clientId): bool
    {
        if ($clientId <= 0) {
            return false;
        }

        $cfg = Capsule::table('tbladdonmodules')
            ->where('module', 'ekdosi_bridge')
            ->whereIn('setting', ['show_client_v2', 'v2_pilot_clients'])
            ->pluck('value', 'setting');

        $enabled = in_array(strtolower((string) ($cfg['show_client_v2'] ?? '')), ['on', 'yes', '1', 'true'], true);
        if (! $enabled) {
            return false;
        }

        $pilot = self::pilotIds((string) ($cfg['v2_pilot_clients'] ?? ''));

        // Empty allowlist = visible to all (once the master switch is on);
        // non-empty = restricted to the listed client ids.
        return $pilot === [] || in_array($clientId, $pilot, true);
    }

    /**
     * The hide/reveal gate for the client-area "Εκδοθέντα Παραστατικά" page —
     * an INDEPENDENT switch from v2 (a tenant may want customers to VIEW their
     * issued documents without exposing the third-party routing editor, or vice
     * versa). Same two-knob shape as visibleTo():
     *   - show_client_issued   (on/off) — master switch, default OFF.
     *   - issued_pilot_clients (csv of WHMCS client ids) — optional allowlist.
     *
     * Guards BOTH the navbar item (hooks.php) AND the page/PDF handlers
     * (ekdosi_bridge_clientarea) so a hidden page can't be reached by URL.
     */
    public static function issuedVisibleTo(int $clientId): bool
    {
        if ($clientId <= 0) {
            return false;
        }

        $cfg = Capsule::table('tbladdonmodules')
            ->where('module', 'ekdosi_bridge')
            ->whereIn('setting', ['show_client_issued', 'issued_pilot_clients'])
            ->pluck('value', 'setting');

        $enabled = in_array(strtolower((string) ($cfg['show_client_issued'] ?? '')), ['on', 'yes', '1', 'true'], true);
        if (! $enabled) {
            return false;
        }

        $pilot = self::pilotIds((string) ($cfg['issued_pilot_clients'] ?? ''));

        return $pilot === [] || in_array($clientId, $pilot, true);
    }

    /** Parse the comma/space-separated pilot client-id list. */
    public static function pilotIds(string $raw): array
    {
        $ids = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            if (ctype_digit($tok)) {
                $ids[] = (int) $tok;
            }
        }

        return $ids;
    }
}
