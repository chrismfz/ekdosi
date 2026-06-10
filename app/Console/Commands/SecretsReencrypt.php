<?php

namespace App\Console\Commands;

use App\Casts\MaybeEncrypted;
use App\Models\Company;
use App\Models\CompanyBackupSetting;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Rewrite every secret column (the `MaybeEncrypted` casts) into the chosen
 * at-rest representation — PLAINTEXT (DR default, mysqldump self-sufficient) or
 * ENCRYPTED (under the current APP_KEY). Run this after flipping
 * `EKDOSI_ENCRYPT_SECRETS_AT_REST` so existing rows match the new mode.
 *
 *   php artisan secrets:reencrypt --to=plain        # decrypt → store plaintext
 *   php artisan secrets:reencrypt --to=encrypted    # encrypt under APP_KEY
 *   php artisan secrets:reencrypt --to=plain --dry-run
 *
 * Reads through the model (so the value is always plaintext in memory, whatever
 * the stored form), then writes the raw column directly (bypassing the cast) so
 * the target representation is explicit and independent of the live flag. Skips
 * columns already in the target form, so it's idempotent + re-runnable.
 */
class SecretsReencrypt extends Command
{
    protected $signature = 'secrets:reencrypt
        {--to= : Target representation: plain | encrypted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Rewrite secret columns as plaintext or APP_KEY-encrypted (DR / key rotation).';

    /** @var list<class-string<Model>> Models carrying MaybeEncrypted secret columns. */
    private const MODELS = [
        Company::class,
        User::class,
        Server::class,
        ServerGroup::class,
        CompanyBackupSetting::class,
    ];

    public function handle(): int
    {
        $to = (string) $this->option('to');
        if (! in_array($to, ['plain', 'encrypted'], true)) {
            $this->error('Δώσε --to=plain ή --to=encrypted.');

            return self::FAILURE;
        }
        $encrypt = $to === 'encrypted';
        $dry = (bool) $this->option('dry-run');

        $totalChanged = 0;
        $totalSkipped = 0;
        foreach (self::MODELS as $modelClass) {
            $columns = $this->secretColumns($modelClass);
            if ($columns === []) {
                continue;
            }

            $skipped = 0;
            $changed = $this->process($modelClass, $columns, $encrypt, $dry, $skipped);
            $totalChanged += $changed;
            $totalSkipped += $skipped;
            $this->line(sprintf('  %-22s %d %s', class_basename($modelClass), $changed, $dry ? 'θα άλλαζαν' : 'ενημερώθηκαν'));
        }

        $this->info(($dry ? 'ΠΡΟΕΠΙΣΚΟΠΗΣΗ — ' : '').'Σύνολο: '.$totalChanged.' στήλες → '.($encrypt ? 'κρυπτογραφημένες' : 'plaintext').'.');
        if ($dry) {
            $this->warn('Dry-run: τίποτα δεν γράφτηκε. Ξανατρέξε χωρίς --dry-run.');
        }
        if ($totalSkipped > 0) {
            // Undecryptable ciphertext was left untouched (wrong/lost APP_KEY) — a
            // non-zero exit so a deploy script notices instead of assuming success.
            $this->error("⚠ {$totalSkipped} στήλες παραλείφθηκαν (κρυπτογραφημένες που δεν ανοίγουν με το τρέχον APP_KEY). Βάλε το σωστό APP_KEY και ξανατρέξε.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string,bool>  $columns  column => isArray
     */
    private function process(string $modelClass, array $columns, bool $encrypt, bool $dry, int &$skipped): int
    {
        $changed = 0;
        $table = (new $modelClass)->getTable();

        // Iterate raw rows so we see the STORED form, and rewrite by primary key.
        $query = $modelClass::query()->withoutGlobalScopes();
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }
        $query->chunkById(200, function ($models) use (
            $table, $columns, $encrypt, $dry, &$changed, &$skipped
        ) {
            foreach ($models as $model) {
                $updates = [];
                foreach ($columns as $column => $isArray) {
                    $raw = $model->getRawOriginal($column);
                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    $plain = MaybeEncrypted::decryptIfPossible((string) $raw);

                    // SAFETY: ciphertext that did NOT decrypt (looks encrypted but
                    // decryptIfPossible returned it unchanged) means the wrong/lost
                    // APP_KEY. Writing it as "plaintext" would freeze an unreadable
                    // blob → silent data loss. Warn + skip, never corrupt.
                    if (! $encrypt && $this->looksEncrypted((string) $raw) && $plain === (string) $raw) {
                        $this->warn("  ⚠ {$table}.{$column} #{$model->getKey()}: κρυπτογραφημένο που ΔΕΝ ανοίγει (λάθος/χαμένο APP_KEY) — παραλείπεται.");
                        $skipped++;

                        continue;
                    }

                    $target = $encrypt ? Crypt::encryptString($plain) : $plain;

                    // Already in the target form? Encrypted target → the raw value
                    // already looks like a Laravel ciphertext blob; plain target →
                    // the raw value already equals the decrypted plaintext.
                    $alreadyTarget = $encrypt
                        ? $this->looksEncrypted((string) $raw)
                        : ((string) $raw === $plain);

                    if ($alreadyTarget) {
                        continue;
                    }

                    $updates[$column] = $target;
                }

                if ($updates !== []) {
                    $changed += count($updates);
                    if (! $dry) {
                        DB::table($table)->where($model->getKeyName(), $model->getKey())->update($updates);
                    }
                }
            }
        }, 'id');

        return $changed;
    }

    /** Best-effort: does the raw value decode as Laravel's encrypted payload shape? */
    private function looksEncrypted(string $value): bool
    {
        $decoded = json_decode(base64_decode($value, true) ?: '', true);

        return is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac']);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string,bool> column => isArray
     */
    private function secretColumns(string $modelClass): array
    {
        $out = [];
        foreach ((new $modelClass)->getCasts() as $column => $cast) {
            if ($cast === MaybeEncrypted::class) {
                $out[$column] = false;
            } elseif (is_string($cast) && str_starts_with($cast, MaybeEncrypted::class.':')) {
                $out[$column] = str_ends_with($cast, ':array');
            }
        }

        return $out;
    }
}
