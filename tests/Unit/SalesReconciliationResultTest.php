<?php

namespace Tests\Unit;

use App\Services\MyData\ReconciliationRow;
use App\Services\MyData\SalesReconciliationResult;
use PHPUnit\Framework\TestCase;

/**
 * The #2 «better insight» split: imported legacy MARKs (production MARK a sandbox
 * can't see) are separated from genuinely-unacknowledged native MARKs, and the
 * headline discrepancyCount excludes the imported noise.
 */
class SalesReconciliationResultTest extends TestCase
{
    public function test_imported_missing_is_split_out_and_excluded_from_discrepancies(): void
    {
        $native = new ReconciliationRow(mark: '400native', invoiceId: 1, legacyId: null);
        $importedA = new ReconciliationRow(mark: '400leg1', invoiceId: 2, legacyId: 11);
        $importedB = new ReconciliationRow(mark: '400leg2', invoiceId: 3, legacyId: 12);

        $result = new SalesReconciliationResult(
            from: '01/04/2026', to: '16/06/2026',
            aadeTotal: 0, localTotal: 3,
            matched: [], stateMismatch: [],
            missingAtAade: [$native, $importedA, $importedB],
            missingLocally: [], duplicateLocal: [],
        );

        $this->assertCount(2, $result->importedMissingAtAade());
        $this->assertCount(1, $result->unacknowledgedMissingAtAade());

        // Only the native unacknowledged MARK is a real discrepancy — the two
        // imported (production) MARKs are sandbox-noise, excluded.
        $this->assertSame(1, $result->discrepancyCount());
        $this->assertTrue($result->hasDiscrepancies());
    }

    public function test_all_imported_means_no_real_discrepancies(): void
    {
        $result = new SalesReconciliationResult(
            from: 'x', to: 'y', aadeTotal: 0, localTotal: 2,
            matched: [], stateMismatch: [],
            missingAtAade: [
                new ReconciliationRow(mark: 'a', invoiceId: 1, legacyId: 5),
                new ReconciliationRow(mark: 'b', invoiceId: 2, legacyId: 6),
            ],
            missingLocally: [], duplicateLocal: [],
        );

        $this->assertSame(0, $result->discrepancyCount());
        $this->assertFalse($result->hasDiscrepancies());
    }
}
