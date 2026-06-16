<?php

namespace Tests\Unit;

use App\Services\MyData\ReconciliationRow;
use App\Services\MyData\SalesReconciliationResult;
use PHPUnit\Framework\TestCase;

/**
 * The #2 «better insight» split: imported legacy MARKs (production MARK a sandbox
 * can't see) are separated from genuinely-unacknowledged native MARKs. Crucially
 * this is MODE-AWARE — imported is noise ONLY on a sandbox connection; in
 * production an imported MARK was filed to the same channel, so its absence is real.
 */
class SalesReconciliationResultTest extends TestCase
{
    private function makeResult(bool $sandbox, array $missingAtAade): SalesReconciliationResult
    {
        return new SalesReconciliationResult(
            from: '01/04/2026', to: '16/06/2026',
            aadeTotal: 0, localTotal: count($missingAtAade),
            matched: [], stateMismatch: [],
            missingAtAade: $missingAtAade,
            missingLocally: [], duplicateLocal: [],
            sandbox: $sandbox,
        );
    }

    public function test_sandbox_excludes_imported_from_discrepancies(): void
    {
        $native = new ReconciliationRow(mark: '400native', invoiceId: 1, legacyId: null);
        $importedA = new ReconciliationRow(mark: '400leg1', invoiceId: 2, legacyId: 11);
        $importedB = new ReconciliationRow(mark: '400leg2', invoiceId: 3, legacyId: 12);

        $r = $this->makeResult(sandbox: true, missingAtAade: [$native, $importedA, $importedB]);

        $this->assertCount(2, $r->importedMissingAtAade());
        $this->assertCount(2, $r->noiseMissingAtAade());     // both imported = sandbox noise
        $this->assertCount(1, $r->realMissingAtAade());      // only the native one
        $this->assertSame(1, $r->discrepancyCount());
    }

    public function test_production_counts_imported_as_real(): void
    {
        $native = new ReconciliationRow(mark: 'a', invoiceId: 1, legacyId: null);
        $imported = new ReconciliationRow(mark: 'b', invoiceId: 2, legacyId: 9);

        $r = $this->makeResult(sandbox: false, missingAtAade: [$native, $imported]);

        // In production an imported MARK was filed to THIS channel — its absence
        // is a genuine discrepancy, so nothing is treated as noise.
        $this->assertCount(0, $r->noiseMissingAtAade());
        $this->assertCount(2, $r->realMissingAtAade());
        $this->assertSame(2, $r->discrepancyCount());
    }

    public function test_sandbox_all_imported_means_no_real_discrepancies(): void
    {
        $r = $this->makeResult(sandbox: true, missingAtAade: [
            new ReconciliationRow(mark: 'a', invoiceId: 1, legacyId: 5),
            new ReconciliationRow(mark: 'b', invoiceId: 2, legacyId: 6),
        ]);

        $this->assertSame(0, $r->discrepancyCount());
        $this->assertFalse($r->hasDiscrepancies());
    }
}
