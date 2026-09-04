<?php

namespace App\Support\Install;

use App\Support\MyData\QrImage;

/**
 * Read-only preflight for the web installer: inspects the PHP runtime + host
 * BEFORE anything is written, so the operator sees «τρέξε πρώτα αυτό» instead
 * of a half-working app. It never CHANGES anything — the privileged steps
 * (`composer install`, enabling an extension) stay at the shell; the installer
 * only verifies their result.
 *
 * Two tiers:
 *  - REQUIRED  → a hard failure BLOCKS the install (the app can't serve/issue
 *                without it). `composer.json` already declares `ext-soap`, so a
 *                missing soap here means someone bypassed with
 *                `--ignore-platform-req` — still blocked.
 *  - OPTIONAL  → a warning that names the ONE feature that won't work
 *                (Firebird ETL, backups, big imports…), never blocking.
 *
 * Every probe goes through a protected seam ({@see extensionLoaded()} etc.) so
 * tests can simulate a missing extension without touching the real runtime.
 */
class RequirementsChecker
{
    /**
     * Extensions the app genuinely cannot serve without. Bar: a miss would make
     * a normal panel/issue flow ERROR, not merely drop one feature. `intl` is
     * here because Filament's `->money('EUR')` (used across ~every dashboard &
     * table) throws without it; `gd`/`curl` are NOT — they degrade (see below).
     */
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql' => 'Σύνδεση στη βάση (MariaDB/MySQL)',
        'mbstring' => 'Ελληνικά/UTF-8 σε όλη την εφαρμογή',
        'openssl' => 'Κρυπτογράφηση, HTTPS κλήσεις, APP_KEY',
        'ctype' => 'Πυρήνας Laravel',
        'tokenizer' => 'Πυρήνας Laravel',
        'dom' => 'Δημιουργία/ανάγνωση XML για myDATA',
        'xml' => 'Δημιουργία/ανάγνωση XML για myDATA',
        'fileinfo' => 'Ανέβασμα αρχείων (λογότυπο, εισαγωγές)',
        'intl' => 'Μορφοποίηση ποσών/ημερομηνιών στο πάνελ (στήλες money) — χωρίς αυτή σκάνε οι οθόνες',
        'soap' => 'Αναζήτηση ΑΦΜ/GSIS σε πελάτες & προμηθευτές',
    ];

    /**
     * Nice-to-have extensions — a miss only disables the named feature, so it
     * WARNS and never blocks. `gd`: a QR failure is swallowed by
     * {@see QrImage::tryDataUri()} → the invoice still
     * issues, just without the printed QR. `curl`: Guzzle falls back to the PHP
     * stream wrapper. Neither justifies blocking an otherwise-capable host.
     */
    private const OPTIONAL_EXTENSIONS = [
        'pdo_firebird' => 'Εισαγωγή από την παλιά βάση Firebird (ETL) — μόνο στον host που τρέχει το migrate:firebird',
        'gd' => 'Εικόνα QR στο PDF παραστατικού — χωρίς αυτή το παραστατικό εκδίδεται κανονικά, απλώς χωρίς το QR',
        'curl' => 'Ταχύτερες/σταθερότερες κλήσεις προς myDATA / WHMCS / GSIS (υπάρχει fallback μέσω PHP streams)',
        'zip' => 'Αντίγραφα ασφαλείας (backups)',
        'bcmath' => 'Ταχύτητα υπολογισμών ποσών (υπάρχει fallback — δουλεύει και χωρίς)',
    ];

    /** @return list<Requirement> in display order (required first, then optional). */
    public function check(): array
    {
        return [
            ...$this->coreChecks(),
            ...$this->requiredExtensionChecks(),
            ...$this->optionalChecks(),
        ];
    }

    /** @param  list<Requirement>  $requirements */
    public function hasBlockers(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if ($requirement->blocks()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Requirement> */
    private function coreChecks(): array
    {
        $php = $this->phpVersion();

        return [
            new Requirement(
                key: 'php_version',
                label: 'PHP ≥ 8.4',
                passed: version_compare($php, '8.4.0', '>='),
                required: true,
                detail: 'Τρέχει PHP '.$php.'.',
                fix: 'Αναβάθμισε την PHP στην έκδοση 8.4 ή νεότερη.',
            ),
            new Requirement(
                key: 'storage_writable',
                label: 'Εγγράψιμο storage/',
                passed: $this->pathWritable($this->storagePath()),
                required: true,
                detail: 'Ο φάκελος storage/ πρέπει να είναι εγγράψιμος (cache, logs, uploads, token εγκατάστασης).',
                fix: 'chmod -R ug+rwX storage && chown -R <web-user> storage',
            ),
            new Requirement(
                key: 'cache_writable',
                label: 'Εγγράψιμο bootstrap/cache/',
                passed: $this->pathWritable($this->cachePath()),
                required: true,
                detail: 'Ο φάκελος bootstrap/cache/ πρέπει να είναι εγγράψιμος (compiled config/routes).',
                fix: 'chmod -R ug+rwX bootstrap/cache && chown -R <web-user> bootstrap/cache',
            ),
            // The installer writes `.env` in the app root as its LAST step —
            // AFTER migrate + ekdosi:install have already mutated the DB (see
            // InstallController::run). If the root isn't writable, the operator
            // ends up with a built database but NO `.env` to boot it. Catch that
            // here (a hard blocker, re-checked server-side before the DB is
            // touched) instead of at the very end when it's too late.
            new Requirement(
                key: 'env_writable',
                label: 'Εγγράψιμος ριζικός φάκελος (.env)',
                passed: $this->envTargetWritable(),
                required: true,
                detail: 'Ο οδηγός γράφει το .env στον ριζικό φάκελο ΩΣ ΤΕΛΕΥΤΑΙΟ βήμα (μετά τη βάση). Αν δεν είναι εγγράψιμος, η εγκατάσταση αφήνει τη βάση φτιαγμένη αλλά χωρίς .env.',
                fix: 'Δώσε δικαίωμα εγγραφής στον ριζικό φάκελο (αυτόν που περιέχει το composer.json) στον χρήστη της PHP-FPM: chmod ug+rwX <root> && chown <web-user> <root>.',
            ),
        ];
    }

    /** @return list<Requirement> */
    private function requiredExtensionChecks(): array
    {
        $checks = [];

        foreach (self::REQUIRED_EXTENSIONS as $ext => $why) {
            $checks[] = new Requirement(
                key: 'ext_'.$ext,
                label: 'Επέκταση PHP: '.$ext,
                passed: $this->extensionLoaded($ext),
                required: true,
                detail: $why.'.',
                fix: 'Εγκατέστησε/ενεργοποίησε την php-'.$ext.' και κάνε restart την PHP-FPM.',
            );
        }

        return $checks;
    }

    /** @return list<Requirement> */
    private function optionalChecks(): array
    {
        $checks = [];

        foreach (self::OPTIONAL_EXTENSIONS as $ext => $why) {
            $checks[] = new Requirement(
                key: 'ext_'.$ext,
                label: 'Επέκταση PHP: '.$ext,
                passed: $this->extensionLoaded($ext),
                required: false,
                detail: $why.'.',
                fix: 'Χωρίς αυτή δεν δουλεύει: '.$why.'. Εγκατέστησε την php-'.$ext.' αν τη χρειάζεσαι.',
            );
        }

        $checks[] = new Requirement(
            key: 'proc_open',
            label: 'Συνάρτηση proc_open',
            passed: $this->functionEnabled('proc_open'),
            required: false,
            detail: 'Χρειάζεται για backups (mysqldump) και επαναφορά Firebird (gbak).',
            fix: 'Αφαίρεσε το proc_open από το disable_functions στο php.ini αν θες backups.',
        );

        // Upload/memory ceilings that bite the import UI (a .fbk can be large).
        $checks[] = $this->byteThreshold('upload_max_filesize', 20 * 1024 * 1024, 'Μέγιστο μέγεθος αρχείου (upload_max_filesize)', 'ανέβασμα .fbk/αρχείων εισαγωγής');
        $checks[] = $this->byteThreshold('post_max_size', 20 * 1024 * 1024, 'Μέγιστο POST (post_max_size)', 'ανέβασμα .fbk/αρχείων εισαγωγής');
        $checks[] = $this->byteThreshold('memory_limit', 256 * 1024 * 1024, 'Όριο μνήμης (memory_limit)', 'μεγάλες εισαγωγές/επαναφορές');

        $checks[] = new Requirement(
            key: 'https',
            label: 'HTTPS',
            passed: $this->requestIsSecure(),
            required: false,
            detail: 'Το site δεν σερβίρεται μέσω HTTPS — απαραίτητο για production (login, myDATA, webhooks).',
            fix: 'Ρύθμισε πιστοποιητικό SSL (π.χ. Let\'s Encrypt) και σέρβιρε το site μέσω https://.',
        );

        return $checks;
    }

    private function byteThreshold(string $iniKey, int $minBytes, string $label, string $feature): Requirement
    {
        $raw = $this->iniRaw($iniKey);
        $bytes = $this->toBytes($raw);
        // 0/unlimited (-1 → bytes 0 here) counts as «no limit» → pass.
        $passed = $bytes === 0 || $bytes >= $minBytes;

        return new Requirement(
            key: 'ini_'.$iniKey,
            label: $label,
            passed: $passed,
            required: false,
            detail: 'Τρέχουσα τιμή: '.($raw !== '' ? $raw : 'χωρίς όριο').' — προτεινόμενο ≥ '.$this->humanBytes($minBytes).' για '.$feature.'.',
            fix: 'Βάλε '.$iniKey.' = '.$this->humanBytes($minBytes).' (ή μεγαλύτερο) στο php.ini (fpm ΚΑΙ cli).',
        );
    }

    /** Parse a php.ini shorthand (e.g. "256M", "1G", "-1") into bytes; -1/empty → 0 (= no limit). */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes % (1024 * 1024 * 1024) === 0) {
            return ($bytes / (1024 * 1024 * 1024)).'G';
        }

        return ((int) ($bytes / (1024 * 1024))).'M';
    }

    // ── seams (overridable in tests) ─────────────────────────────────────────

    protected function phpVersion(): string
    {
        return PHP_VERSION;
    }

    protected function extensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    protected function functionEnabled(string $function): bool
    {
        if (! function_exists($function)) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array($function, $disabled, true);
    }

    protected function pathWritable(string $path): bool
    {
        return is_writable($path);
    }

    protected function iniRaw(string $key): string
    {
        return (string) ini_get($key);
    }

    protected function requestIsSecure(): bool
    {
        return request()->isSecure();
    }

    protected function storagePath(): string
    {
        return storage_path();
    }

    protected function cachePath(): string
    {
        return base_path('bootstrap/cache');
    }

    protected function envTargetDir(): string
    {
        return base_path();
    }

    /**
     * Is the exact directory the installer writes `.env` into writable?
     * {@see EnvWriter::write()} does temp-file + `rename()` in this dir — both
     * need the SAME write+execute bits on the directory that `is_writable()`
     * tests, and `is_writable()` also reports a read-only mount, so a plain stat
     * is faithful here without the side effects of an actual write-probe (this
     * runs on every wizard render — the class stays read-only, like the sibling
     * storage/cache checks). ACL/SELinux corner cases where `access()` and a real
     * write disagree are host misconfigurations the operator must fix regardless.
     */
    protected function envTargetWritable(): bool
    {
        $dir = $this->envTargetDir();

        return is_dir($dir) && is_writable($dir);
    }
}
