<?php

namespace App\Services\Portability;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Seals/opens the handful of secret values that ride inside a company export
 * bundle, decoupling at-rest encryption (APP_KEY, per VM) from transport
 * encryption (a passphrase the operator carries) — see
 * FEATURES.md → Secrets.
 *
 * Two modes:
 *   - passphrase (default): each secret is encrypted under a key derived from
 *     the operator's passphrase (PBKDF2-SHA256 + a per-bundle random salt) via
 *     Laravel's authenticated AES-256-GCM encrypter. The plaintext never lands
 *     in the bundle; the same passphrase decrypts it on the target VM, where it
 *     is then re-encrypted under that VM's APP_KEY on save.
 *   - raw: secrets stored in clear text. The explicit operator opt-out for a
 *     debug dump on a trusted box — never the silent default, blocked for remote
 *     destinations by the caller.
 *
 * Values are JSON-encoded before sealing so a non-string secret (e.g. the
 * einvoice_provider_config array) round-trips with its type intact.
 */
class SecretsCodec
{
    private const PBKDF2_ITERATIONS = 120000;

    private const CIPHER = 'aes-256-gcm';

    /**
     * @param  array<string,mixed>  $secrets  plaintext column => value
     * @return array{mode:string, salt?:string, values:array<string,?string>}
     */
    public function seal(array $secrets, string $mode, ?string $passphrase): array
    {
        $encoded = $this->encode($secrets);

        if ($mode === 'raw') {
            return ['mode' => 'raw', 'values' => $encoded];
        }

        if ($mode !== 'passphrase') {
            throw new RuntimeException("Unknown secrets mode: «{$mode}».");
        }

        $this->requirePassphrase($passphrase);

        $salt = random_bytes(16);
        $enc = $this->encrypterFor($passphrase, $salt);

        $values = [];
        foreach ($encoded as $key => $value) {
            $values[$key] = $value === null ? null : $enc->encrypt($value, false);
        }

        return ['mode' => 'passphrase', 'salt' => base64_encode($salt), 'values' => $values];
    }

    /**
     * @param  array{mode:string, salt?:string, values:array<string,?string>}  $sealed
     * @return array<string,mixed> plaintext column => value
     */
    public function open(array $sealed, ?string $passphrase): array
    {
        $mode = $sealed['mode'] ?? null;

        if ($mode === 'raw') {
            return $this->decode($sealed['values'] ?? []);
        }

        if ($mode !== 'passphrase') {
            throw new RuntimeException("Unknown secrets mode: «{$mode}».");
        }

        $this->requirePassphrase($passphrase);

        $salt = base64_decode((string) ($sealed['salt'] ?? ''), true);
        if ($salt === false || $salt === '') {
            throw new RuntimeException('Κατεστραμμένο ή απών salt στο αρχείο αντιγράφου.');
        }

        $enc = $this->encrypterFor($passphrase, $salt);

        $values = [];
        foreach (($sealed['values'] ?? []) as $key => $value) {
            $values[$key] = $value === null ? null : $enc->decrypt($value, false);
        }

        return $this->decode($values);
    }

    private function requirePassphrase(?string $passphrase): void
    {
        if (! is_string($passphrase) || $passphrase === '') {
            throw new RuntimeException('Απαιτείται συνθηματικό (passphrase) για το κρυπτογραφημένο αντίγραφο.');
        }
    }

    private function encrypterFor(string $passphrase, string $salt): Encrypter
    {
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ITERATIONS, 32, true);

        return new Encrypter($key, self::CIPHER);
    }

    /**
     * @param  array<string,mixed>  $secrets
     * @return array<string,?string>
     */
    private function encode(array $secrets): array
    {
        $out = [];
        foreach ($secrets as $key => $value) {
            $out[$key] = $value === null
                ? null
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $out;
    }

    /**
     * @param  array<string,?string>  $values
     * @return array<string,mixed>
     */
    private function decode(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[$key] = $value === null ? null : json_decode($value, true);
        }

        return $out;
    }
}
