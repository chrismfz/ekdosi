<?php

namespace App\Services\Backup;

use App\Models\Company;
use App\Models\CompanyBackupRun;
use App\Models\CompanyBackupSetting;
use App\Services\Portability\BundleArchive;
use App\Services\Portability\CompanyExporter;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Produces ONE company backup: build the bundle (CompanyExporter), zip it
 * (BundleArchive), fan it out to every configured destination (registry), prune
 * per retention, and write a CompanyBackupRun audit row. `local` is always
 * included (the Download source). One destination failing → `partial`, not a
 * lost run; a build failure → `failed` with the message. Shared by the scheduled
 * command and the «τώρα» UI action.
 */
class CompanyBackupRunner
{
    public function __construct(
        private readonly CompanyExporter $exporter,
        private readonly BundleArchive $archive,
        private readonly BackupDestinationRegistry $registry,
    ) {}

    public function run(Company $company, CompanyBackupSetting $settings, string $trigger = 'manual'): CompanyBackupRun
    {
        $run = new CompanyBackupRun([
            'company_id' => $company->id,
            'started_at' => now(),
            'trigger' => $trigger,
            'bucket' => $settings->bucket,
            'secrets_mode' => $settings->secrets_mode,
        ]);

        $tmp = null;
        try {
            [$mode, $passphrase] = $this->resolveSecrets($settings);
            $bundle = $this->exporter->build($company, $mode, $passphrase, $settings->wantsFull());

            $tmp = storage_path('app/tmp/'.$company->slug.'-'.$settings->bucket.'-'.now()->format('Ymd-His').'.zip');
            $this->archive->write($tmp, $bundle);
            $run->bytes = is_file($tmp) ? (filesize($tmp) ?: null) : null;

            $results = [];
            $localPath = null;
            foreach ($settings->destinationList() as $entry) {
                $key = (string) $entry['driver'];
                $config = (array) ($entry['config'] ?? []);
                try {
                    $dest = $this->registry->for($key);
                    $location = $dest->push($tmp, $company->slug, $config);
                    $dest->prune($company->slug, (int) $settings->retention_keep, $settings->retention_days, $config);
                    $results[] = ['driver' => $key, 'status' => 'ok', 'location' => $location];
                    if ($key === 'local') {
                        $localPath = $location;
                    }
                } catch (Throwable $e) {
                    $results[] = ['driver' => $key, 'status' => 'failed', 'message' => $e->getMessage()];
                }
            }

            $okCount = count(array_filter($results, static fn ($r) => $r['status'] === 'ok'));
            $run->destinations = $results;
            $run->bundle_path = $localPath;
            $run->status = match (true) {
                $okCount === count($results) => 'ok',
                $okCount === 0 => 'failed',
                default => 'partial',
            };
            $run->message = $run->status === 'ok' ? null : 'Κάποιοι προορισμοί απέτυχαν — δες λεπτομέρειες ανά προορισμό.';
        } catch (Throwable $e) {
            $run->status = 'failed';
            $run->message = $e->getMessage();
        } finally {
            // The local destination keeps its own permanent copy; drop the temp build.
            if ($tmp !== null && is_file($tmp)) {
                File::delete($tmp);
            }
        }

        $run->finished_at = now();
        $run->save();

        return $run;
    }

    /** @return array{0:string, 1:?string} [mode, passphrase] */
    private function resolveSecrets(CompanyBackupSetting $settings): array
    {
        if ($settings->secrets_mode === 'raw') {
            return ['raw', null];
        }

        $passphrase = (string) ($settings->passphrase ?? '');
        if ($passphrase === '') {
            throw new RuntimeException('Δεν έχει οριστεί συνθηματικό για κρυπτογραφημένο αντίγραφο (secrets_mode=passphrase).');
        }

        return ['passphrase', $passphrase];
    }
}
