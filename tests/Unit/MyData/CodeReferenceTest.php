<?php

namespace Tests\Unit\MyData;

use App\Support\MyData\ClassificationGuidance;
use App\Support\MyData\CodeReference;
use App\Support\MyData\Codes;
use PHPUnit\Framework\TestCase;

/**
 * The «Οδηγός κωδικών myDATA» glossary data. Guards that every §8 code the panel
 * shows has a title (so a spec addition to Codes can't leave a blank row) and that
 * the operator-named example «2.1» carries a real explanation.
 */
class CodeReferenceTest extends TestCase
{
    public function test_sections_cover_the_expected_families(): void
    {
        $keys = array_map(fn ($s) => $s['key'], CodeReference::sections());
        $this->assertSame(
            ['business_activity', 'income_buckets', 'invoice_types', 'vat_categories', 'vat_exemptions'],
            $keys
        );
    }

    public function test_every_row_has_a_code_title_and_detail(): void
    {
        foreach (CodeReference::sections() as $section) {
            $this->assertNotSame('', $section['title']);
            $this->assertNotSame([], $section['rows'], "section {$section['key']} has no rows");
            foreach ($section['rows'] as $row) {
                $this->assertArrayHasKey('code', $row);
                $this->assertNotSame('', (string) $row['title'], "empty title in {$section['key']}");
                $this->assertNotSame('', (string) $row['detail'], "empty detail in {$section['key']}");
                // The page exists to EXPLAIN — a bare «—» defeats it. Every selectable
                // code gets at least a friendly fallback (review finding).
                $this->assertNotSame('—', (string) $row['detail'],
                    "code {$row['code']} in {$section['key']} shows a bare dash instead of an explanation");
            }
        }
    }

    public function test_invoice_types_section_covers_every_spec_code_and_explains_2_1(): void
    {
        $rows = collect(CodeReference::sections())->firstWhere('key', 'invoice_types')['rows'];
        $byCode = collect($rows)->keyBy('code');

        // Every §8.1 code from the authoritative table is present (no silent gap).
        foreach (array_keys(Codes::INVOICE_TYPES) as $code) {
            $this->assertTrue($byCode->has($code), "invoice type {$code} missing from the guide");
        }

        // The operator's example: 2.1 → service invoice, with a real «where used» note.
        $this->assertStringContainsString('Παροχή', $byCode['2.1']['title']);
        $this->assertStringContainsString('ΥΠΗΡΕΣΙΩΝ', $byCode['2.1']['detail']);
    }

    public function test_income_buckets_section_distinguishes_merchandise_from_own_products(): void
    {
        $rows = collect(CodeReference::sections())->firstWhere('key', 'income_buckets')['rows'];
        $byCode = collect($rows)->keyBy('code');

        $this->assertTrue($byCode->has('category1_1'));
        $this->assertTrue($byCode->has('category1_2'));
        $this->assertStringContainsString('ΜΕΤΑΠΩΛΕΙΣ', $byCode['category1_1']['detail']);
        $this->assertStringContainsString('ΠΑΡΑΓΕΙΣ', $byCode['category1_2']['detail']);
    }

    public function test_vat_and_exemption_sections_track_the_codes_tables(): void
    {
        $sections = collect(CodeReference::sections());

        $vat = collect($sections->firstWhere('key', 'vat_categories')['rows'])->pluck('code')->all();
        foreach (array_keys(Codes::VAT_CATEGORY_LABELS) as $code) {
            $this->assertContains((string) $code, $vat, "VAT category {$code} missing");
        }

        $exemptions = collect($sections->firstWhere('key', 'vat_exemptions')['rows'])->pluck('code')->all();
        foreach (array_keys(Codes::VAT_EXEMPTION_LABELS) as $code) {
            $this->assertContains((string) $code, $exemptions, "exemption {$code} missing");
        }
    }

    public function test_business_activity_section_matches_the_policy_helper(): void
    {
        $rows = collect(CodeReference::sections())->firstWhere('key', 'business_activity')['rows'];
        $this->assertSame(
            array_keys(ClassificationGuidance::POLICIES),
            collect($rows)->pluck('code')->all()
        );
    }
}
