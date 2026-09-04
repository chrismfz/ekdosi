<?php

namespace App\Services\Portability;

use RuntimeException;
use ZipArchive;

/**
 * Serialises a CompanyExporter bundle to / from a `.zip`, so the command AND the
 * Filament UI share one reader/writer instead of duplicating ZipArchive glue.
 *
 * Layout: manifest.json + company.json + secrets.json + users.json +
 * setup/<table>.json + data/<table>.json (full bundle only) + files/<name>.
 */
class BundleArchive
{
    /**
     * @param  array<string,mixed>  $bundle  CompanyExporter::build() shape
     */
    public function write(string $path, array $bundle): void
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
        // Assigned operators (email/name/role, no passwords). Optional key so an
        // older bundle without it still round-trips.
        $zip->addFromString('users.json', $json($bundle['users'] ?? []));
        foreach ($bundle['setup'] as $table => $rows) {
            $zip->addFromString("setup/{$table}.json", $json($rows));
        }
        // Transactional rows — only present in a `--full` bundle. MUST be written
        // too, else a "full" backup silently ships zero transactional data.
        foreach (($bundle['data'] ?? []) as $table => $rows) {
            $zip->addFromString("data/{$table}.json", $json($rows));
        }
        foreach ($bundle['files'] as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();
    }

    /**
     * @return array<string,mixed> CompanyExporter::build() shape
     */
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Αδυναμία ανάγνωσης zip: {$path}");
        }

        $read = static function (string $name) use ($zip): ?array {
            $raw = $zip->getFromName($name);

            return $raw === false ? null : json_decode($raw, true);
        };

        $manifest = $read('manifest.json');
        $company = $read('company.json');
        $secrets = $read('secrets.json');
        if ($manifest === null || $company === null || $secrets === null) {
            $zip->close();
            throw new RuntimeException('Μη έγκυρο αρχείο: λείπει manifest/company/secrets.');
        }
        // Optional (absent in older bundles) → default to no assigned operators.
        $users = $read('users.json') ?? [];

        $setup = [];
        $data = [];
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'setup/') && str_ends_with($name, '.json')) {
                $table = substr($name, strlen('setup/'), -strlen('.json'));
                $setup[$table] = json_decode((string) $zip->getFromName($name), true) ?? [];
            } elseif (str_starts_with($name, 'data/') && str_ends_with($name, '.json')) {
                $table = substr($name, strlen('data/'), -strlen('.json'));
                $data[$table] = json_decode((string) $zip->getFromName($name), true) ?? [];
            } elseif (str_starts_with($name, 'files/')) {
                $files[$name] = (string) $zip->getFromName($name);
            }
        }
        $zip->close();

        return compact('manifest', 'company', 'secrets', 'users', 'setup', 'data', 'files');
    }
}
