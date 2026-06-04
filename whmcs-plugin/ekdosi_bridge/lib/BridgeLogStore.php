<?php

namespace WHMCS\Module\Addon\EkdosiBridge;

use Throwable;
use WHMCS\Database\Capsule;

/**
 * Bridge request log — visibility for the Plugin-API.
 *
 * Every call ekdosi makes to resolve.php (the Plugin-API) records one row here:
 * which op, from which IP, the HTTP status, and a short result (count / found /
 * the auth-failure reason). The admin «Bridge logs» tab renders it, so an
 * operator can see — from the side that is ALWAYS up (WHMCS) — exactly what
 * ekdosi asks, how often, and whether anything is failing.
 *
 * This is the counterpart that would have made the ~1.5-day silent fetch outage
 * obvious: when ekdosi's cron died it stopped POLLING, and a "last inbound poll:
 * 1.5 days ago" banner here is visible to the WHMCS admin who looks daily — no
 * ekdosi-side process needed to raise the alarm. A wiped/rotated secret shows up
 * the same way: rows with http_status 401/422.
 *
 * READ-ONLY w.r.t. WHMCS data. Self-contained own table (created by SchemaGuard,
 * no reactivation needed). Recording is best-effort — it NEVER throws into the
 * request path (a logging failure must not break a data response).
 */
class BridgeLogStore
{
    public const TABLE = 'mod_ekdosi_bridge_log';

    /**
     * The op that represents ekdosi's SCHEDULED bulk inbox poll — its absence
     * over time is the outage signal the «last inbound poll» banner watches.
     * Deliberately ONLY 'invoices' (the every-15-min feed): 'invoice' is the
     * single-fetch push/probe path, which a one-off «Αποστολή» or a
     * `whmcs:use-bridge` probe would otherwise use to falsely refresh the banner
     * while the real scheduled feed is dead — masking the very outage it detects.
     */
    private const INBOUND_POLL_OPS = ['invoices'];

    /** Retain ~30 days; pruned probabilistically on insert to keep it bounded. */
    private const RETENTION_DAYS = 30;

    public static function ensureTable(): void
    {
        Capsule::statement(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            .'created_at DATETIME NOT NULL,'
            .'op VARCHAR(40) NOT NULL DEFAULT \'\','
            .'ip VARCHAR(45) NOT NULL DEFAULT \'\','
            .'ok TINYINT(1) NOT NULL DEFAULT 0,'
            .'http_status SMALLINT NOT NULL DEFAULT 0,'
            .'result VARCHAR(190) NOT NULL DEFAULT \'\','
            .'PRIMARY KEY (id), KEY idx_created (created_at)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Record one Plugin-API request. Best-effort: any failure (table not yet
     * created, no INSERT grant) is swallowed so the data response is unaffected.
     */
    public static function record(string $op, string $ip, bool $ok, int $status, string $result = ''): void
    {
        try {
            Capsule::table(self::TABLE)->insert([
                'created_at' => date('Y-m-d H:i:s'),
                'op' => substr($op !== '' ? $op : '?', 0, 40),
                'ip' => substr($ip, 0, 45),
                'ok' => $ok ? 1 : 0,
                'http_status' => $status,
                'result' => substr($result, 0, 190),
            ]);

            // Bounded growth without a scheduled job: ~2% of inserts prune the
            // tail. Cheap and self-maintaining.
            if (random_int(1, 50) === 1) {
                Capsule::table(self::TABLE)
                    ->where('created_at', '<', date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400))
                    ->delete();
            }
        } catch (Throwable $e) {
            // Never let logging affect the request.
        }
    }

    /**
     * Most recent rows (newest first).
     *
     * @return array<int, object>
     */
    public static function recent(int $limit = 80): array
    {
        try {
            return Capsule::table(self::TABLE)
                ->orderBy('id', 'desc')
                ->limit(max(1, $limit))
                ->get()
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** When ekdosi last successfully PULLED the feed (op=invoices|invoice). Null = never. */
    public static function lastInboundPollAt(): ?string
    {
        try {
            $v = Capsule::table(self::TABLE)
                ->whereIn('op', self::INBOUND_POLL_OPS)
                ->where('ok', 1)
                ->max('created_at');

            return ($v !== null && $v !== '') ? (string) $v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * At-a-glance numbers for the landing + the «Bridge logs» tab.
     *
     * @return array{total24:int, fail24:int, last_at:?string, last_inbound_poll_at:?string, last_auth_fail_at:?string}
     */
    public static function summary(): array
    {
        $out = [
            'total24' => 0,
            'fail24' => 0,
            'last_at' => null,
            'last_inbound_poll_at' => self::lastInboundPollAt(),
            'last_auth_fail_at' => null,
        ];
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);
            $out['total24'] = (int) Capsule::table(self::TABLE)->where('created_at', '>=', $since)->count();
            $out['fail24'] = (int) Capsule::table(self::TABLE)->where('created_at', '>=', $since)->where('ok', 0)->count();
            $lastAt = Capsule::table(self::TABLE)->max('created_at');
            $out['last_at'] = ($lastAt !== null && $lastAt !== '') ? (string) $lastAt : null;
            $authFail = Capsule::table(self::TABLE)->whereIn('http_status', [401, 422])->max('created_at');
            $out['last_auth_fail_at'] = ($authFail !== null && $authFail !== '') ? (string) $authFail : null;
        } catch (Throwable $e) {
            // partial summary is fine
        }

        return $out;
    }
}
