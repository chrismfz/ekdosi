<?php

namespace Tests\Feature\Portability;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\CompanyImporter;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * The "don't forget" guard: EVERY tenant-owned model (one using BelongsToCompany)
 * MUST be classified for company export — in a SETUP/TRANSACTIONAL bucket, or
 * explicitly in CompanyExporter::INTENTIONALLY_EXCLUDED (with a reason). When a
 * future feature adds a new `company_id` table (e.g. ψηφιακό πελατολόγιο /
 * per-company contacts), this test FAILS until someone classifies it — so a new
 * table can never silently fall out of backup / export / restore.
 */
class CompanyExportCoverageTest extends TestCase
{
    #[Test]
    public function every_tenant_owned_table_is_classified_for_export(): void
    {
        $known = array_merge(
            CompanyExporter::SETUP_TABLES,
            CompanyExporter::TRANSACTIONAL_TABLES,
            CompanyExporter::SEALED_TABLES,
            CompanyExporter::INTENTIONALLY_EXCLUDED,
        );

        $unclassified = [];
        foreach ($this->tenantOwnedModels() as $model) {
            $table = (new $model)->getTable();
            if (! in_array($table, $known, true)) {
                $unclassified[$table] = $model;
            }
        }

        $this->assertSame(
            [],
            $unclassified,
            "These BelongsToCompany tables are not classified for company export.\n"
            ."Add each to a bucket (CompanyExporter::SETUP_TABLES / TRANSACTIONAL_TABLES)\n"
            ."or to CompanyExporter::INTENTIONALLY_EXCLUDED (with a reason):\n  "
            .implode("\n  ", array_map(fn ($m, $t) => "{$t}  ←  {$m}", $unclassified, array_keys($unclassified)))
        );
    }

    #[Test]
    public function every_exported_table_is_also_imported(): void
    {
        // The exporter writes SETUP_TABLES/TRANSACTIONAL_TABLES into the bundle, but
        // the IMPORTER only restores what's in its ORDER / ORDER_TRANSACTIONAL lists.
        // A table in a setup/tx bucket but missing from the matching import ORDER is
        // exported then silently dropped on restore — this guards that round-trip.
        $importOrder = array_merge(
            $this->privateConst(CompanyImporter::class, 'ORDER'),
            $this->privateConst(CompanyImporter::class, 'ORDER_TRANSACTIONAL'),
        );

        $exported = array_merge(CompanyExporter::SETUP_TABLES, CompanyExporter::TRANSACTIONAL_TABLES);

        $this->assertSame(
            [],
            array_values(array_diff($exported, $importOrder)),
            'These tables are EXPORTED but not in CompanyImporter::ORDER/ORDER_TRANSACTIONAL — '
            .'they would be silently dropped on restore. Add each to the matching import order list.'
        );
    }

    /** @return list<string> */
    private function privateConst(string $class, string $name): array
    {
        return (array) (new ReflectionClass($class))->getConstant($name);
    }

    #[Test]
    public function the_bucket_lists_do_not_overlap(): void
    {
        $setup = CompanyExporter::SETUP_TABLES;
        $tx = CompanyExporter::TRANSACTIONAL_TABLES;
        $sealed = CompanyExporter::SEALED_TABLES;
        $excluded = CompanyExporter::INTENTIONALLY_EXCLUDED;

        $this->assertSame([], array_intersect($setup, $tx), 'A table is in BOTH setup and transactional buckets.');
        $this->assertSame([], array_intersect($setup, $excluded), 'A table is both setup and excluded.');
        $this->assertSame([], array_intersect($tx, $excluded), 'A table is both transactional and excluded.');
        $this->assertSame([], array_intersect($sealed, array_merge($setup, $tx, $excluded)), 'A sealed table is also in another bucket.');
    }

    /**
     * Every concrete App\Models class that uses the BelongsToCompany trait.
     *
     * @return list<class-string<Model>>
     */
    private function tenantOwnedModels(): array
    {
        $models = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }
            if (in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        return $models;
    }
}
