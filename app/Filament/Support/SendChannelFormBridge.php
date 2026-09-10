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
        $currentKey = trim((string) $record?->einvoice_provider_key);

        foreach (self::fieldMap() as $key => $fields) {
            foreach (array_keys($fields) as $field) {
                // Pre-fill the CURRENT provider's fields (incl. secrets) with the stored
                // value so the operator can reveal + copy-paste a token — same UX as the
                // myDATA subscription-key inputs (which map to decrypted `encrypted`
                // columns and are ->revealable()). The provider inputs stay
                // ->password()->revealable(); a blank submit still means "keep the
                // stored value" via the ->dehydrated(filled) rule in dehydrate().
                //
                // ANOTHER provider's fields start BLANK — but ONLY when we know who the
                // blob belongs to. Two cases, and conflating them loses credentials:
                //
                //  - The record HAS a provider key: the blob is that provider's, so a
                //    different provider's fields must not inherit it. The blob is flat,
                //    so pre-filling them hands the next provider the previous one's value
                //    wherever the two share a field name (`base_url` is on both invosign
                //    and sbz) — which then SAVES, pointing the new provider at the old
                //    one's endpoint and defeating the rotation hygiene dehydrate()
                //    implements by starting a switched provider's blob empty.
                //
                //  - The record has NO provider key (parked on a myDATA channel, which
                //    keeps the blob deliberately): the blob still belongs to the LAST
                //    provider, and nothing records which one. Blanking here made
                //    invosign → «Καθόλου» → invosign write an EMPTY config, destroying an
                //    encrypted token with no way back. So pre-fill, as before.
                //
                // KNOWN RESIDUAL (docs/BACKLOG.md, pinned by a test): on that second
                // route the carry-over survives — «πάροχος Α → Καθόλου → πάροχος Β»
                // still hands Β the shared fields of Α. Closing it needs the blob to
                // record its OWNER; that is a schema change, not a form tweak, and
                // losing tokens is the worse of the two failures.
                $ownerKnown = $currentKey !== '';
                $data["cfg_{$key}_{$field}"] = (! $ownerKnown || $key === $currentKey)
                    ? ($config[$field] ?? null)
                    : null;
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
            // Compare NORMALISED on both sides. Company::einvoiceProviderKey() cleans
            // on write and a migration repaired the old rows, so these agree today —
            // but the consequence of them disagreeing is silently dropping the stored
            // credentials (this seed is the only thing that carries them across a save
            // where the input didn't come back), and that is too expensive to leave
            // resting on an exact string match.
            $sameProvider = (trim((string) $record?->einvoice_provider_key) === trim((string) $providerKey));
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
