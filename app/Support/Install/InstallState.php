<?php

namespace App\Support\Install;

/**
 * Fail-closed «is ekdosi already installed?» oracle for the web installer.
 *
 * The whole installer (routes, wizard, the destructive migrate/create-admin
 * step) is gated on {@see canInstall()} — which is TRUE only on a positively
 * PRISTINE host: no completion marker AND no configured `.env`. Anything else
 * (a real `.env` with an APP_KEY, or the marker we drop on success) means the
 * app is set up, so the installer must be permanently INERT — this is the
 * «αν υπάρχει env … κάντο άχρηστο, δεν πρέπει να ξανα-τρέξει» safety rule.
 *
 * Deliberately filesystem-only (no DB): it runs in global middleware on EVERY
 * request, before the DB/session stack, and must not itself need a database or
 * an APP_KEY. The DB-already-populated guard (refuse to overwrite a live DB)
 * lives at the migrate step in the controller, WITH the operator-supplied
 * credentials — never as an unauthenticated probe here.
 */
class InstallState
{
    /** Completion marker — dropped on a successful install; its presence alone locks the installer. */
    public function markerPath(): string
    {
        return storage_path('app/install/installed.json');
    }

    /**
     * TRUE once the app is configured. Two signals, filesystem/config only (no
     * DB — this runs in global middleware on every request):
     *
     *  1. A completion marker file (dropped on a successful web install).
     *  2. A non-empty RESOLVED APP_KEY (`config('app.key')`). This is the
     *     load-bearing check and it reads the resolved config, NOT the `.env`
     *     file — so it's correct whether the key comes from `.env`, a real
     *     server env var, or CI/phpunit env (parsing `.env` would wrongly flag
     *     a test/CI box — which has no `.env` file — as pristine and redirect
     *     every request to /install). An empty key is the precise «fresh drop,
     *     nothing configured» trigger the installer exists for.
     */
    public function isInstalled(): bool
    {
        if (is_file($this->markerPath())) {
            return true;
        }

        return $this->configuredAppKey() !== '';
    }

    /** The resolved app key, trimmed. Empty string = not configured. */
    public function configuredAppKey(): string
    {
        return trim((string) config('app.key'));
    }

    /** The installer may run ONLY on a pristine host. */
    public function canInstall(): bool
    {
        return ! $this->isInstalled();
    }

    /**
     * Drop the completion marker (best-effort). Written LAST in the install
     * flow, alongside the freshly-written `.env`, so a half-failed install
     * (which writes neither) leaves the wizard available for a safe retry.
     */
    public function markInstalled(array $meta = []): void
    {
        $path = $this->markerPath();

        @mkdir(dirname($path), 0775, true);

        @file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
