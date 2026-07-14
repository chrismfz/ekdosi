<?php

namespace App\Support\Install;

/**
 * Renders + atomically writes the `.env` the web installer produces.
 *
 * Only the CORE keys the app needs to boot go here (app identity, DB, session/
 * cache/queue, mail, logging). Per-tenant secrets (myDATA / WHMCS / GSIS /
 * provider creds) are NEVER in `.env` — they live per-company in the DB, set
 * later from the panel. The full catalogue of optional EKDOSI_* knobs stays in
 * `.env.example`; a pointer comment sends the operator there.
 *
 * The write is atomic (temp file + rename) so a crash never leaves a truncated
 * `.env`, and it's the LAST thing the install flow does — a mid-way failure
 * writes no `.env`, keeping the wizard available for a safe retry.
 */
class EnvWriter
{
    public function path(): string
    {
        return base_path('.env');
    }

    /** Generate a fresh Laravel APP_KEY (AES-256 → 32 random bytes, base64). */
    public function generateAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * Build the `.env` body from validated wizard values.
     *
     * @param  array<string, string|bool>  $v  keys: app_name, app_env, app_key,
     *                                         app_url, app_locale, app_timezone, db_host, db_port, db_database,
     *                                         db_username, db_password, mail_mailer, mail_host, mail_port,
     *                                         mail_username, mail_password, mail_encryption, mail_from_address,
     *                                         mail_from_name
     */
    public function render(array $v): string
    {
        $isProd = ($v['app_env'] ?? 'production') === 'production';
        $isHttps = str_starts_with(strtolower((string) ($v['app_url'] ?? '')), 'https://');

        $lines = [];
        $lines[] = '# Δημιουργήθηκε από τον οδηγό εγκατάστασης ekdosi.';
        $lines[] = '# Προαιρετικές ρυθμίσεις (scheduler, backups, AI, myDATA cron κ.λπ.): δες .env.example.';
        $lines[] = '';
        $lines[] = 'APP_NAME='.$this->quote((string) ($v['app_name'] ?? 'ekdosi'));
        $lines[] = 'APP_ENV='.($v['app_env'] ?? 'production');
        $lines[] = 'APP_KEY='.($v['app_key'] ?? '');
        $lines[] = 'APP_DEBUG='.($isProd ? 'false' : 'true');
        $lines[] = 'APP_URL='.$this->quote((string) ($v['app_url'] ?? 'http://localhost'));
        $lines[] = 'APP_TIMEZONE='.($v['app_timezone'] ?? 'Europe/Athens');
        $lines[] = '';
        $lines[] = 'APP_LOCALE='.($v['app_locale'] ?? 'el');
        $lines[] = 'APP_FALLBACK_LOCALE=en';
        $lines[] = 'APP_FAKER_LOCALE=el_GR';
        $lines[] = '';
        $lines[] = 'BCRYPT_ROUNDS=12';
        $lines[] = '';
        $lines[] = 'LOG_CHANNEL=stack';
        $lines[] = 'LOG_STACK='.($isProd ? 'daily' : 'single');
        $lines[] = 'LOG_DEPRECATIONS_CHANNEL=null';
        $lines[] = 'LOG_LEVEL='.($isProd ? 'info' : 'debug');
        $lines[] = '';
        $lines[] = 'DB_CONNECTION=mariadb';
        $lines[] = 'DB_HOST='.($v['db_host'] ?? '127.0.0.1');
        $lines[] = 'DB_PORT='.($v['db_port'] ?? '3306');
        $lines[] = 'DB_DATABASE='.$this->quote((string) ($v['db_database'] ?? 'ekdosi'));
        $lines[] = 'DB_USERNAME='.$this->quote((string) ($v['db_username'] ?? 'ekdosi'));
        $lines[] = 'DB_PASSWORD='.$this->quote((string) ($v['db_password'] ?? ''));
        $lines[] = '';
        $lines[] = 'SESSION_DRIVER=database';
        $lines[] = 'SESSION_LIFETIME=120';
        $lines[] = 'SESSION_ENCRYPT=false';
        $lines[] = 'SESSION_PATH=/';
        $lines[] = 'SESSION_DOMAIN=null';
        // Only force secure cookies when the site is actually served over HTTPS —
        // setting it on a plain-HTTP box would break login (the cookie never returns).
        if ($isProd && $isHttps) {
            $lines[] = 'SESSION_SECURE_COOKIE=true';
        }
        $lines[] = '';
        $lines[] = 'BROADCAST_CONNECTION=log';
        $lines[] = 'FILESYSTEM_DISK=local';
        $lines[] = 'QUEUE_CONNECTION=database';
        $lines[] = 'CACHE_STORE=database';
        $lines[] = '';
        $lines[] = 'MAIL_MAILER='.($v['mail_mailer'] ?? 'log');
        $lines[] = 'MAIL_HOST='.($v['mail_host'] ?? '127.0.0.1');
        $lines[] = 'MAIL_PORT='.($v['mail_port'] ?? '2525');
        $lines[] = 'MAIL_USERNAME='.$this->quote((string) ($v['mail_username'] ?? ''), 'null');
        $lines[] = 'MAIL_PASSWORD='.$this->quote((string) ($v['mail_password'] ?? ''), 'null');
        $lines[] = 'MAIL_SCHEME='.($v['mail_encryption'] ?? 'null');
        $lines[] = 'MAIL_FROM_ADDRESS='.$this->quote((string) ($v['mail_from_address'] ?? 'hello@example.com'));
        $lines[] = 'MAIL_FROM_NAME='.$this->quote((string) ($v['mail_from_name'] ?? ($v['app_name'] ?? 'ekdosi')));
        $lines[] = '';
        $lines[] = '# Secrets αποθηκεύονται ΩΣ ΕΧΟΥΝ (plaintext) — όριο εμπιστοσύνης = πρόσβαση DB/δίσκου.';
        $lines[] = '# Ρητή αποδοχή ώστε να περνά το go-live-check (δες docs/security-at-rest.md).';
        $lines[] = 'EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED=true';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Atomic write: temp file in the same directory + rename (so the swap is a
     * single filesystem operation and a reader never sees a half-written file).
     *
     * @throws \RuntimeException on any failure
     */
    public function write(string $contents): void
    {
        $path = $this->path();
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException("Αδυναμία εγγραφής προσωρινού .env ({$tmp}). Έλεγξε τα δικαιώματα του φακέλου.");
        }

        @chmod($tmp, 0640);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException("Αδυναμία εγγραφής του .env ({$path}). Έλεγξε τα δικαιώματα του φακέλου.");
        }
    }

    /**
     * Quote a value for `.env` when it needs it (spaces, `#`, `=`, quotes).
     * An empty string becomes the given placeholder (default: empty), so
     * MAIL_USERNAME= with no value can read as `null` where the config expects it.
     */
    private function quote(string $value, string $emptyAs = ''): string
    {
        if ($value === '') {
            return $emptyAs;
        }

        if (preg_match('/[\s#"\'=$]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
