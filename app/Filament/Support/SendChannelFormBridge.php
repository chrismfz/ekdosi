<?php

namespace App\Filament\Support;

use App\Models\Company;
use App\Support\EInvoice\SendChannel;

/**
 * Bridges the operator-friendly synthetic form fields (one flat «Τρόπος αποστολής»
 * dropdown + labeled provider-credential inputs) to/from the real storage columns,
 * so the Company form never exposes raw JSON or the four underlying columns
 * (einvoice_provider / mydata_mode / einvoice_provider_key / einvoice_provider_mode
 * + the encrypted einvoice_provider_config blob). Pure array-in/array-out → unit
 * tested without Filament. Used by CreateCompany/EditCompany page hooks (P3).
 *
 * Synthetic fields:
 *   send_channel            — the flat dropdown (SendChannel keys)
 *   cfg_<provider>_<field>  — one labeled input per provider credential field
 *                             (from config ekdosi.einvoice.provider_fields)
 */
class SendChannelFormBridge
{
    /** mutateFormDataBeforeFill: inject the synthetic fields FROM a record (or defaults). */
    public static function hydrate(array $data, ?Company $record): array
    {
        $data['send_channel'] = $record ? SendChannel::fromCompany($record) : SendChannel::FALLBACK;

        $config = is_array($record?->einvoice_provider_config) ? $record->einvoice_provider_config : [];
        foreach (self::fieldMap() as $key => $fields) {
            foreach (array_keys($fields) as $field) {
                // Pre-fill EVERY field (incl. secrets) with the stored value so the
                // operator can reveal + copy-paste a token — same UX as the myDATA
                // subscription-key inputs (which map to decrypted `encrypted` columns
                // and are ->revealable()). The provider inputs stay
                // ->password()->revealable(); a blank submit still means "keep the
                // stored value" via the ->dehydrated(filled) rule in dehydrate().
                $data["cfg_{$key}_{$field}"] = $config[$field] ?? null;
            }
        }

        return $data;
    }

    /** mutateFormDataBeforeCreate/Save: decompose the channel + assemble the config; strip synthetics. */
    public static function dehydrate(array $data, ?Company $record): array
    {
        $channel = (string) ($data['send_channel'] ?? SendChannel::FALLBACK);
        $data = array_merge($data, SendChannel::decompose($channel));

        $providerKey = $data['einvoice_provider_key'] ?? null;
        if ($providerKey !== null) {
            // Seed from the EXISTING config ONLY when staying on the SAME provider —
            // that's what preserves a blank-submitted secret. Switching to a DIFFERENT
            // provider starts clean, so the previous provider's encrypted secret does
            // not linger in the blob (secret hygiene on rotation).
            $sameProvider = ($record?->einvoice_provider_key === $providerKey);
            $existing = is_array($record?->einvoice_provider_config) ? $record->einvoice_provider_config : [];
            $config = $sameProvider ? $existing : [];
            foreach (array_keys(self::fieldMap()[$providerKey] ?? []) as $field) {
                $value = $data["cfg_{$providerKey}_{$field}"] ?? null;
                // Blank → keep the stored value (no null/empty noise in the blob). This
                // is the "leave blank to keep" rule for secrets, applied uniformly:
                // credentials are changed, not cleared, via this form.
                if ($value !== null && $value !== '') {
                    $config[$field] = $value;
                }
            }
            $data['einvoice_provider_config'] = $config;
        }
        // For a myDATA channel we leave einvoice_provider_config untouched (harmless
        // stale blob; routing is decided by einvoice_provider, not the config).

        // Strip every synthetic key so the model fill sees only real columns.
        unset($data['send_channel']);
        foreach (self::fieldMap() as $key => $fields) {
            foreach (array_keys($fields) as $field) {
                unset($data["cfg_{$key}_{$field}"]);
            }
        }

        return $data;
    }

    /** @return array<string, array<string, array{label?:string, secret?:bool}>> */
    private static function fieldMap(): array
    {
        $map = config('ekdosi.einvoice.provider_fields', []);

        return is_array($map) ? $map : [];
    }
}
