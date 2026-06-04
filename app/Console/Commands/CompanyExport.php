<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Portability\CompanyExporter;
use Illuminate\Console\Command;
use RuntimeException;
use ZipArchive;

/**
 * Phase 1 (docs/company-portability-plan.md) — export a tenant's SETTINGS +
 * SETUP to a portable .zip. Non-destructive. Secrets are passphrase-encrypted
 * by default (option 5); `--raw` opts into a cleartext debug dump.
 *
 *   php artisan company:export --tenant=myip
 *   php artisan company:export --tenant=myip --out=/path/myip.zip --passphrase=…
 *   php artisan company:export --tenant=myip --raw          # cleartext (danger)
 */
class CompanyExport extends Command
{
    protected $signature = 'company:export
        {--tenant= : Company slug}
        {--out= : Output .zip path (default: storage/app/exports/…)}
        {--passphrase= : Encrypt secrets with this passphrase (else prompted)}
        {--raw : Store secrets in CLEAR TEXT — debug only}
        {--full : Include transactional data (Phase 2 — not yet)}';

    protected $description = 'Export a company\'s settings + setup to a portable .zip (Phase 1)';

    public function handle(CompanyExporter $exporter): int
    {
        if ($this->option('full')) {
            $this->error('Το πλήρες αντίγραφο (με συναλλακτικά δεδομένα) δεν υλοποιήθηκε ακόμη (Phase 2).');

            return self::FAILURE;
        }

        $slug = (string) $this->option('tenant');
        if ($slug === '') {
            $this->error('Δώσε --tenant=SLUG.');

            return self::FAILURE;
        }

        $company = Company::query()->where('slug', $slug)->first();
        if ($company === null) {
            $this->error("Δεν βρέθηκε εταιρία με slug «{$slug}».");

            return self::FAILURE;
        }

        try {
            [$mode, $passphrase] = $this->resolveSecretsMode();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $bundle = $exporter->build($company, $mode, $passphrase);

        $out = (string) ($this->option('out')
            ?: storage_path('app/exports/'.$slug.'-settings-'.now()->format('Ymd-His').'.zip'));

        try {
            $this->writeZip($out, $bundle);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Εξήχθη: {$out}");
        $this->line('  secrets mode: '.$bundle['manifest']['secrets_mode']
            .($mode === 'raw' ? '  ⚠ CLEAR TEXT' : ''));
        foreach ($bundle['manifest']['counts'] as $table => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %-22s %d', $table, $count));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0:string, 1:?string} [mode, passphrase]
     */
    private function resolveSecretsMode(): array
    {
        if ($this->option('raw')) {
            $this->warn('⚠ RAW mode: τα μυστικά (κλειδιά myDATA/GSIS/SMTP/WHMCS) θα γραφτούν σε ΚΑΘΑΡΟ ΚΕΙΜΕΝΟ στο αρχείο.');
            if (! $this->option('no-interaction') && ! $this->confirm('Συνέχεια με μυστικά σε καθαρό κείμενο;')) {
                throw new RuntimeException('Ακυρώθηκε.');
            }

            return ['raw', null];
        }

        $passphrase = (string) $this->option('passphrase');
        if ($passphrase === '') {
            if ($this->option('no-interaction')) {
                throw new RuntimeException('Δώσε --passphrase ή --raw.');
            }
            $passphrase = (string) $this->secret('Συνθηματικό κρυπτογράφησης');
            if ($passphrase === '' || $passphrase !== (string) $this->secret('Επιβεβαίωση συνθηματικού')) {
                throw new RuntimeException('Τα συνθηματικά δεν ταιριάζουν (ή είναι κενά).');
            }
        }

        return ['passphrase', $passphrase];
    }

    /**
     * @param  array<string,mixed>  $bundle
     */
    private function writeZip(string $path, array $bundle): void
    {
        if (! is_dir($dir = dirname($path)) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Αδυναμία δημιουργίας φακέλου: {$dir}");
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Αδυναμία εγγραφής zip: {$path}");
        }

        $json = static fn (array $data): string => (string) json_encode(
            $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $zip->addFromString('manifest.json', $json($bundle['manifest']));
        $zip->addFromString('company.json', $json($bundle['company']));
        $zip->addFromString('secrets.json', $json($bundle['secrets']));
        foreach ($bundle['setup'] as $table => $rows) {
            $zip->addFromString("setup/{$table}.json", $json($rows));
        }
        foreach ($bundle['files'] as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();
    }
}
