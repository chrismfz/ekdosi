<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Drop-in replacement for Laravel's `encrypted` / `encrypted:array` cast whose
 * encryption is OPTIONAL, driven by `config('ekdosi.secrets.encrypt_at_rest')`.
 *
 * Why: when encryption is OFF (the DR default) the secret columns are stored as
 * PLAINTEXT, so a plain mysqldump is self-sufficient — a restore on a new VM
 * needs no APP_KEY. See config/ekdosi.php → secrets.encrypt_at_rest and
 * `php artisan secrets:reencrypt`.
 *
 * Robustness: the GETTER ALWAYS tries to decrypt first and falls back to the raw
 * value, so it transparently reads BOTH legacy ciphertext AND plaintext
 * regardless of the current flag — flipping the flag never breaks existing rows.
 * The SETTER encrypts only when the flag is ON.
 *
 * Usage (model casts):
 *   'gsis_password'           => MaybeEncrypted::class,
 *   'einvoice_provider_config' => MaybeEncrypted::class.':array',
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class MaybeEncrypted implements CastsAttributes
{
    public function __construct(private readonly string $type = 'string')
    {
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $plain = self::decryptIfPossible((string) $value);

        return $this->isArray() ? json_decode($plain, true) : $plain;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $plain = $this->isArray()
            ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $value;

        return [$key => self::shouldEncrypt() ? Crypt::encryptString($plain) : $plain];
    }

    private function isArray(): bool
    {
        return $this->type === 'array';
    }

    public static function shouldEncrypt(): bool
    {
        return (bool) config('ekdosi.secrets.encrypt_at_rest', false);
    }

    /**
     * Decrypt a Laravel-encrypted string; return the input unchanged when it is
     * NOT decryptable (plaintext, or ciphertext under a different/lost APP_KEY).
     */
    public static function decryptIfPossible(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    /**
     * Does a cast string denote a secret column (the Laravel `encrypted*` casts
     * OR this one)? The single predicate every secret-aware site uses
     * (CompanyExporter, the export-coverage test) so a column can't silently
     * drop out of the sealed set when its cast changes from `encrypted` to this.
     */
    public static function isSecretCast(?string $cast): bool
    {
        if ($cast === null) {
            return false;
        }

        return $cast === 'encrypted'
            || str_starts_with($cast, 'encrypted:')
            || $cast === self::class
            || str_starts_with($cast, self::class.':');
    }
}
