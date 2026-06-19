<?php

namespace Tests\Unit;

use App\DTOs\AadeRegistryRecord;
use PHPUnit\Framework\TestCase;

class AadeRegistryRecordTest extends TestCase
{
    private function record(array $activities): AadeRegistryRecord
    {
        return new AadeRegistryRecord(
            afm: '801017172', name: 'PROCOMAX IKE', doy: 'ΚΕΦΟΔΕ ΑΤΤΙΚΗΣ', doyCode: '1',
            active: true, statusDescr: 'ΕΝΕΡΓΟΣ ΑΦΜ', address: 'ΑΝΑΚΡΕΟΝΤΟΣ 3',
            city: 'ΠΕΡΙΣΤΕΡΙ', postcode: '12136', activities: $activities,
        );
    }

    public function test_primary_activity_description_is_capped_to_occupation_length(): void
    {
        // AADE ships multi-hundred-char activity descriptions; occupation columns
        // are VARCHAR(120). primaryActivity() must cap the description so customer
        // import doesn't 22001-overflow (regression: PROCOMAX ΑΦΜ 801017172, whose
        // GSIS description is ~300 chars of «ΥΠΗΡΕΣΙΕΣ ΑΝΤΙΠΡΟΣΩΠΟΥ … ΚΛΠ»).
        $long = str_repeat('ΥΠΗΡΕΣΙΕΣ ΑΝΤΙΠΡΟΣΩΠΟΥ ΚΙΝΗΤΗΣ ΤΗΛΕΦΩΝΙΑΣ ', 20); // ~840 chars

        $primary = $this->record([
            ['code' => '61900000', 'description' => $long, 'kind' => 'ΚΥΡΙΑ'],
        ])->primaryActivity();

        $this->assertNotNull($primary);
        $this->assertLessThanOrEqual(AadeRegistryRecord::OCCUPATION_MAX_LENGTH, mb_strlen($primary['description']));
        $this->assertSame('61900000', $primary['code']); // code untouched
        $this->assertStringStartsWith('ΥΠΗΡΕΣΙΕΣ ΑΝΤΙΠΡΟΣΩΠΟΥ', $primary['description']);
    }

    public function test_short_description_is_left_intact(): void
    {
        $primary = $this->record([
            ['code' => '62010000', 'description' => 'Υπηρεσίες προγραμματισμού', 'kind' => 'ΚΥΡΙΑ'],
        ])->primaryActivity();

        $this->assertSame('Υπηρεσίες προγραμματισμού', $primary['description']);
    }

    public function test_fallback_to_first_activity_also_caps(): void
    {
        // No entry self-identifies as primary → first is returned, still capped.
        $long = str_repeat('Α', 300);

        $primary = $this->record([
            ['code' => '11111111', 'description' => $long, 'kind' => 'ΔΕΥΤΕΡΕΥΟΥΣΑ'],
        ])->primaryActivity();

        $this->assertNotNull($primary);
        $this->assertSame(AadeRegistryRecord::OCCUPATION_MAX_LENGTH, mb_strlen($primary['description']));
    }
}
