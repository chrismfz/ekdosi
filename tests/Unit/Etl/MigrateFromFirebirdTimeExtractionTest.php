<?php

namespace Tests\Unit\Etl;

use App\Console\Commands\MigrateFromFirebird;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * pdo_firebird returns Firebird TIME columns in two shapes depending on
 * driver build: a pure "HH:MM:SS" string, OR a full "Y-m-d H:i:s" string
 * where the date half is whatever today is. Both must normalise to the
 * pure TIME so downstream MariaDB TIME columns accept the value AND the
 * mergeDateTime() concatenation produces a valid DATETIME for the
 * mydata_marks.created_at audit timestamp.
 *
 * Regression for the "2021-05-31 2021-05-" mangled created_at bug.
 */
class MigrateFromFirebirdTimeExtractionTest extends TestCase
{
    private function invoke(string $method, array $args): mixed
    {
        $cmd = new MigrateFromFirebird();
        $m = new ReflectionMethod($cmd, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($cmd, $args);
    }

    public function test_extract_time_handles_pure_time_string(): void
    {
        $this->assertSame('15:50:42', $this->invoke('extractTime', ['15:50:42']));
    }

    public function test_extract_time_handles_full_datetime_string(): void
    {
        // The bug shape: pdo_firebird returns TIME prefixed with date.
        $this->assertSame('15:50:42', $this->invoke('extractTime', ['2021-05-31 15:50:42']));
    }

    public function test_extract_time_returns_midnight_on_null(): void
    {
        $this->assertSame('00:00:00', $this->invoke('extractTime', [null]));
        $this->assertSame('00:00:00', $this->invoke('extractTime', ['']));
    }

    public function test_extract_time_returns_midnight_when_no_time_token(): void
    {
        $this->assertSame('00:00:00', $this->invoke('extractTime', ['garbage']));
    }

    public function test_merge_date_time_produces_valid_datetime_for_both_input_shapes(): void
    {
        // Pure-TIME shape (legacy assumption)
        $this->assertSame(
            '2021-05-31 15:50:42',
            $this->invoke('mergeDateTime', ['2021-05-31', '15:50:42'])
        );
        // Datetime-shape TIME (the production bug)
        $this->assertSame(
            '2021-05-31 15:50:42',
            $this->invoke('mergeDateTime', ['2021-05-31', '2021-05-31 15:50:42'])
        );
    }

    public function test_merge_date_time_returns_null_when_date_missing(): void
    {
        $this->assertNull($this->invoke('mergeDateTime', [null, '15:50:42']));
    }
}
