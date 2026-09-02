<?php

namespace Tests\Unit\MyData;

use App\Support\MyData\ClassificationGuidance;
use App\Support\MyData\Codes;
use PHPUnit\Framework\TestCase;

/**
 * MYD-006: pins the business-activity → §8.6 goods-bucket mapping. The exact
 * defect was a single hardwired category1_1 goods default that mis-files a
 * manufacturer's own products (which are category1_2). These lock the mapping,
 * the refuse-rather-than-guess boundary (services/mixed/unset → no goods
 * default), and that every advertised bucket is a real §8.6 category.
 */
class ClassificationGuidanceTest extends TestCase
{
    public function test_reseller_files_goods_as_merchandise_category1_1(): void
    {
        $this->assertSame('category1_1', ClassificationGuidance::goodsCategoryFor(ClassificationGuidance::RESELLER));
    }

    public function test_manufacturer_files_goods_as_own_products_category1_2(): void
    {
        // The historic defect: a manufacturer's own products are NOT merchandise.
        $this->assertSame('category1_2', ClassificationGuidance::goodsCategoryFor(ClassificationGuidance::MANUFACTURER));
        $this->assertNotSame('category1_1', ClassificationGuidance::goodsCategoryFor(ClassificationGuidance::MANUFACTURER));
    }

    public function test_services_and_mixed_and_unset_have_no_tenant_wide_goods_default(): void
    {
        // Services → the type already files category1_3; mixed → per product-category;
        // unset/unknown → no policy. All return null so the caller leaves the line
        // untouched rather than guessing a bucket.
        $this->assertNull(ClassificationGuidance::goodsCategoryFor(ClassificationGuidance::SERVICES));
        $this->assertNull(ClassificationGuidance::goodsCategoryFor(ClassificationGuidance::MIXED));
        $this->assertNull(ClassificationGuidance::goodsCategoryFor(null));
        $this->assertNull(ClassificationGuidance::goodsCategoryFor('nonsense'));
    }

    public function test_only_mixed_requires_per_category_configuration(): void
    {
        $this->assertTrue(ClassificationGuidance::requiresPerCategoryConfig(ClassificationGuidance::MIXED));
        foreach ([ClassificationGuidance::RESELLER, ClassificationGuidance::MANUFACTURER, ClassificationGuidance::SERVICES, null] as $t) {
            $this->assertFalse(ClassificationGuidance::requiresPerCategoryConfig($t));
        }
    }

    public function test_is_valid_accepts_only_the_four_policies(): void
    {
        foreach (['reseller', 'manufacturer', 'services', 'mixed'] as $t) {
            $this->assertTrue(ClassificationGuidance::isValid($t));
        }
        $this->assertFalse(ClassificationGuidance::isValid(null));
        $this->assertFalse(ClassificationGuidance::isValid(''));
        $this->assertFalse(ClassificationGuidance::isValid('freelancer'));
    }

    public function test_options_cover_the_policies_and_carry_labels(): void
    {
        $options = ClassificationGuidance::options();
        $this->assertSame(array_keys(ClassificationGuidance::POLICIES), array_keys($options));
        foreach ($options as $key => $label) {
            $this->assertNotSame('', $label, "policy {$key} has an empty label");
            $this->assertSame($label, ClassificationGuidance::labelFor($key));
            $this->assertNotNull(ClassificationGuidance::hintFor($key));
        }
    }

    public function test_every_goods_category_is_a_real_spec_bucket(): void
    {
        foreach (ClassificationGuidance::POLICIES as $key => $policy) {
            $bucket = $policy['goods_category'];
            if ($bucket === null) {
                continue;
            }
            $this->assertContains(
                $bucket,
                Codes::INCOME_CLASS_CATEGORIES,
                "Policy {$key} uses §8.6 bucket {$bucket} which is not a valid income category."
            );
        }
    }

    public function test_label_and_hint_are_null_for_an_unknown_type(): void
    {
        $this->assertNull(ClassificationGuidance::labelFor('nope'));
        $this->assertNull(ClassificationGuidance::hintFor(null));
    }

    public function test_bucket_options_and_labels_cover_the_three_item_natures(): void
    {
        $options = ClassificationGuidance::bucketOptions();
        $this->assertSame(['category1_1', 'category1_2', 'category1_3'], array_keys($options));
        // Every §8.6 bucket is a valid income category and its option carries the code.
        foreach ($options as $code => $label) {
            $this->assertContains($code, Codes::INCOME_CLASS_CATEGORIES);
            $this->assertStringContainsString($code, $label);
            $this->assertNotNull(ClassificationGuidance::bucketLabel($code));
        }
        $this->assertNull(ClassificationGuidance::bucketLabel(null));
        $this->assertNull(ClassificationGuidance::bucketLabel(''));
        $this->assertNull(ClassificationGuidance::bucketLabel('category9_9'));
    }

    public function test_describe_product_bucket_is_definitive_when_the_category_sets_it(): void
    {
        $desc = ClassificationGuidance::describeProductBucket('category1_2', ClassificationGuidance::RESELLER);
        $this->assertStringContainsString('category1_2', $desc);
        $this->assertStringContainsString('από την κατηγορία', $desc); // definitive, from the category
    }

    public function test_describe_product_bucket_explains_inheritance_when_the_category_is_blank(): void
    {
        // Manufacturer, no category bucket → describes goods→category1_2 inheritance.
        $manu = ClassificationGuidance::describeProductBucket(null, ClassificationGuidance::MANUFACTURER);
        $this->assertStringContainsString('Κληρονομείται', $manu);
        $this->assertStringContainsString('category1_2', $manu);

        // Services / unset → no goods bucket asserted, just inherits the type.
        $services = ClassificationGuidance::describeProductBucket(null, ClassificationGuidance::SERVICES);
        $this->assertStringContainsString('Κληρονομείται', $services);
        $this->assertStringNotContainsString('category1_1', $services);
        $this->assertStringNotContainsString('category1_2', $services);
    }
}
