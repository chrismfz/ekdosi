<?php

namespace App\Support\Install;

/**
 * Filesystem-ownership gate for the web installer.
 *
 * The wizard asks for secrets only the real admin knows (the DB credentials),
 * but «better safe than sorry»: before the installer will do ANYTHING it also
 * demands proof that whoever is driving the browser also controls the SERVER.
 * On first visit we write a random token to a file OUTSIDE the web root
 * (`storage/app/install/…`); the operator must open it over SSH / the hosting
 * panel's file manager and paste it back. A stranger who merely found the URL
 * can't read it.
 *
 * The token is re-verified on EVERY installer POST (the wizard carries it in a
 * hidden field) — there's no session to lean on before the app is configured.
 * On a successful install {@see clear()} removes it.
 */
class InstallTokenManager
{
    public function path(): string
    {
        return storage_path('app/install/verify-token.txt');
    }

    /**
     * Return the current token, creating it (and the directory) on first call.
     * The file leads with a human explanation so an operator opening it knows
     * exactly what it is and why. Returns null only if the directory/file
     * can't be created (a permissions problem the wizard then surfaces).
     */
    public function issue(): ?string
    {
        $existing = $this->token();

        if ($existing !== null) {
            return $existing;
        }

        $path = $this->path();

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
            return null;
        }

        $token = bin2hex(random_bytes(16));

        $body = "# ekdosi — κωδικός επιβεβαίωσης εγκατάστασης\n"
            ."#\n"
            ."# Αυτό το αρχείο δημιουργήθηκε αυτόματα από τον οδηγό εγκατάστασης.\n"
            ."# Αντίγραψε τον παρακάτω κωδικό και επικόλλησέ τον στη σελίδα\n"
            ."# εγκατάστασης, για να αποδείξεις ότι έχεις πρόσβαση στον διακομιστή.\n"
            ."# Μόλις ολοκληρωθεί η εγκατάσταση, το αρχείο διαγράφεται αυτόματα.\n"
            ."#\n"
            .$token."\n";

        if (@file_put_contents($path, $body) === false) {
            return null;
        }

        @chmod($path, 0600);

        return $token;
    }

    /** The stored token, or null if none has been issued yet (or it's unreadable). */
    public function token(): ?string
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        // The token is the first non-comment, non-blank line.
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            return $line;
        }

        return null;
    }

    /** Constant-time comparison of the operator's input against the stored token. */
    public function verify(?string $input): bool
    {
        $token = $this->token();

        if ($token === null || $input === null) {
            return false;
        }

        return hash_equals($token, trim($input));
    }

    /** Remove the token file once the install has completed. */
    public function clear(): void
    {
        $path = $this->path();

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
