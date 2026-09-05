<?php

namespace App\Contracts;

/**
 * Opt-in for a PaymentGateway whose `config` holds write-only secrets (a shared
 * secret / API secret key). Names the config keys that must NEVER be loaded back
 * into the edit form (so a stored secret can't be read from the browser) and are
 * preserved on save when the field is left blank (editing other fields never
 * wipes the secret). Kept off the base interface — a gateway with no secret
 * (manual/offline) doesn't implement it.
 */
interface HasSecretConfig
{
    /** @return list<string> config keys that are write-only secrets */
    public function secretConfigKeys(): array;
}
