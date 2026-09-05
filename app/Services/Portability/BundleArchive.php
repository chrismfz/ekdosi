<?php

namespace App\Services\Portability;

use RuntimeException;
use ZipArchive;

/**
 * Serialises a CompanyExporter bundle to / from a `.zip`, so the command AND the
 * Filament UI share one reader/writer instead of duplicating ZipArchive glue.
 *
 * Layout: manifest.json + company.json + secrets.json + users.json +
 * connections.json + setup/<table>.json + data/<table>.json (full bundle only) +
 * files/<name>.
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
        // Sealed payment-method connections (rows + passphrase-sealed config).
        // Optional key so an older bundle without it still round-trips.
        $zip->addFromString('connections.json', $json($bundle['connections'] ?? []));
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
     * Read ONLY the small header files (manifest + secrets) WITHOUT inflating the
     * whole archive — for a caller that just needs the company identity and to
     * verify the passphrase (the web installer's pre-migrate gate). Avoids
     * decoding every setup/data/files entry into memory twice on a big bundle.
     *
     * @return array{manifest: array<string,mixed>, secrets: array<string,mixed>}
     */
    public function readHeader(string $path): array
    {
        $zip = $this->openOrFail($path);

        $manifest = $this->readJsonEntry($zip, 'manifest.json');
        $secrets = $this->readJsonEntry($zip, 'secrets.json');
        // company.json isn't returned here, but decode it too (it's one small
        // row) so the gate matches read()'s EXACT invariant — a present-but-
        // corrupt company.json must fail BEFORE migrate, not after (which would
        // leave a migrated, empty DB). Existence alone wouldn't catch corruption.
        $company = $this->readJsonEntry($zip, 'company.json');
        $zip->close();

        if ($manifest === null || $secrets === null || $company === null) {
            throw new RuntimeException('Μη έγκυρο αρχείο: λείπει/κατεστραμμένο manifest/company/secrets.');
        }

        return compact('manifest', 'secrets');
    }

    /**
     * @return array<string,mixed> CompanyExporter::build() shape
     */
    public function read(string $path): array
    {
        $zip = $this->openOrFail($path);

        $manifest = $this->readJsonEntry($zip, 'manifest.json');
        $company = $this->readJsonEntry($zip, 'company.json');
        $secrets = $this->readJsonEntry($zip, 'secrets.json');
        if ($manifest === null || $company === null || $secrets === null) {
            $zip->close();
            throw new RuntimeException('Μη έγκυρο αρχείο: λείπει manifest/company/secrets.');
        }
        // Optional (absent in older bundles) → default to no assigned operators.
        $users = $this->readJsonEntry($zip, 'users.json') ?? [];
        // Optional (absent in older bundles) → default to no connections. The
        // importer no-ops on empty rows, so a pre-connections bundle round-trips.
        $connections = $this->readJsonEntry($zip, 'connections.json') ?? [];

        $setup = [];
        $data = [];
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'setup/') && str_ends_with($name, '.json')) {
                $table = substr($name, strlen('setup/'), -strlen('.json'));
                $setup[$table] = $this->requireJsonEntry($zip, $name);
            } elseif (str_starts_with($name, 'data/') && str_ends_with($name, '.json')) {
                $table = substr($name, strlen('data/'), -strlen('.json'));
                $data[$table] = $this->requireJsonEntry($zip, $name);
            } elseif (str_starts_with($name, 'files/')) {
                $files[$name] = (string) $zip->getFromName($name);
            }
        }
        $zip->close();

        return compact('manifest', 'company', 'secrets', 'users', 'connections', 'setup', 'data', 'files');
    }

    private function openOrFail(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Αδυναμία ανάγνωσης zip: {$path}");
        }

        return $zip;
    }

    /**
     * Decode one JSON entry to an array, or null when it is absent OR not a JSON
     * object/array (a bare scalar would otherwise raise a TypeError instead of the
     * intended «invalid file» error).
     *
     * @return array<mixed>|null
     */
    private function readJsonEntry(ZipArchive $zip, string $name): ?array
    {
        $raw = $zip->getFromName($name);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A present setup/data table entry that fails to decode to an array is
     * CORRUPT — throw rather than silently import it as an empty table (silent
     * data loss on a restore). An empty table legitimately serialises as `[]`.
     *
     * @return array<mixed>
     */
    private function requireJsonEntry(ZipArchive $zip, string $name): array
    {
        $decoded = $this->readJsonEntry($zip, $name);
        if ($decoded === null) {
            $zip->close();
            throw new RuntimeException("Κατεστραμμένο αρχείο στο bundle: {$name}");
        }

        return $decoded;
    }
}
