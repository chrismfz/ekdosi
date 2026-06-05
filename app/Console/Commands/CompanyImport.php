<?php

namespace App\Console\Commands;

use App\Services\Portability\BundleArchive;
use App\Services\Portability\CompanyImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Phase 1 (import half) — restore a company settings+setup .zip produced by
 * `company:export`. Dry-run by default (prints the per-table plan); `--execute`
 * applies. Idempotent upsert by natural key (never delete+insert).
 *
 *   php artisan company:import --file=myip.zip --new
 *   php artisan company:import --file=myip.zip --into=myip --execute
 */
class CompanyImport extends Command
{
    protected $signature = 'company:import
        {--file= : Path to the exported .zip}
        {--new : Create a new company from the bundle}
        {--into= : Restore into an EXISTING company (slug)}
        {--passphrase= : Passphrase to open the secrets (else prompted)}
        {--execute : Apply (default is a dry-run preview)}';

    protected $description = 'Restore a company settings + setup .zip (Phase 1, dry-run by default)';

    public function handle(CompanyImporter $importer): int
    {
        $file = (string) $this->option('file');
        if ($file === '' || ! is_file($file)) {
            $this->error('Δώσε υπαρκτό --file=path/to/bundle.zip.');

            return self::FAILURE;
        }

        $new = (bool) $this->option('new');
        $into = $this->option('into');
        if ($new === ($into !== null)) {
            $this->error('Δώσε ΕΙΤΕ --new ΕΙΤΕ --into=SLUG (όχι και τα δύο, ούτε κανένα).');

            return self::FAILURE;
        }

        try {
            $bundle = app(BundleArchive::class)->read($file);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $passphrase = $this->resolvePassphrase($bundle['secrets']['mode'] ?? 'passphrase');
            $summary = $importer->run($bundle, [
                'new' => $new,
                'into' => $into,
                'execute' => (bool) $this->option('execute'),
                'passphrase' => $passphrase,
            ]);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(($summary['dry_run'] ? 'ΠΡΟΕΠΙΣΚΟΠΗΣΗ (dry-run)' : 'ΕΦΑΡΜΟΣΤΗΚΕ')
            .' — εταιρία: '.$summary['company'].' «'.$summary['slug'].'»');
        foreach ($summary['tables'] as $table => $counts) {
            $this->line(sprintf('  %-22s +%d new, ~%d update',
                $table, $counts['insert'] ?? 0, $counts['update'] ?? 0));
        }
        if ($summary['dry_run']) {
            $this->warn('Dry-run: τίποτα δεν γράφτηκε. Ξανατρέξε με --execute για εφαρμογή.');
        }

        return self::SUCCESS;
    }

    private function resolvePassphrase(string $mode): ?string
    {
        if ($mode === 'raw') {
            return null;
        }

        $passphrase = (string) $this->option('passphrase');
        if ($passphrase === '') {
            if ($this->option('no-interaction')) {
                throw new RuntimeException('Δώσε --passphrase για το κρυπτογραφημένο αρχείο.');
            }
            $passphrase = (string) $this->secret('Συνθηματικό του αρχείου');
        }

        return $passphrase;
    }
}
