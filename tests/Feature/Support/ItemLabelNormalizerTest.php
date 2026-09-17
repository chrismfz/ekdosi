<?php

namespace Tests\Feature\Support;

use App\Support\Accounting\ItemLabelNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The item-label normaliser behind «Ισοζύγιο Ειδών/Υπηρεσιών» (#4): it strips the
 * trailing billing-period date-range from a free-text line so renewals of the same
 * package fold together, while leaving non-date parentheticals (part of the name)
 * and interior specs intact.
 */
class ItemLabelNormalizerTest extends TestCase
{
    public function test_strips_a_trailing_date_range_parenthetical(): void
    {
        $this->assertSame(
            'Dedicate Server Cloud Hosting 16C/64GB/1TB/1G)',
            ItemLabelNormalizer::clean('Dedicate Server Cloud Hosting 16C/64GB/1TB/1G) (1/9/2026-31/8/2027)'),
        );
    }

    public function test_strips_a_spaced_zero_padded_date_range(): void
    {
        $this->assertSame(
            'Web Hosting Business',
            ItemLabelNormalizer::clean('Web Hosting Business (01/09/2026 - 31/08/2027)'),
        );
    }

    public function test_two_renewals_of_the_same_package_share_a_key(): void
    {
        $a = ItemLabelNormalizer::key('Cloud VPS 4GB (1/1/2025-31/12/2025)');
        $b = ItemLabelNormalizer::key('Cloud VPS 4GB (1/1/2026-31/12/2026)');

        $this->assertSame($a, $b);
        $this->assertSame('cloud vps 4gb', $a);
    }

    public function test_keeps_a_non_date_parenthetical(): void
    {
        // «(Annual Plan)» / «(Server Setup Fee)» are part of the product name.
        $this->assertSame('Office 365 Premium Business License (Annual Plan)', ItemLabelNormalizer::clean('Office 365 Premium Business License (Annual Plan)'));
        $this->assertSame('Εργασία Εγκατάστασης (Server Setup Fee)', ItemLabelNormalizer::clean('Εργασία Εγκατάστασης (Server Setup Fee)'));
    }

    public function test_keeps_a_version_or_ip_parenthetical_not_a_date(): void
    {
        // A numeric triple WITHOUT a 19xx/20xx year is a version / IP, not a billing
        // period — must be kept (else «SSL (v1.2.3)» and «SSL (v2.0.1)» wrongly fold).
        $this->assertSame('SSL Certificate (v1.2.3)', ItemLabelNormalizer::clean('SSL Certificate (v1.2.3)'));
        $this->assertSame('Static IP (192.168.1.5)', ItemLabelNormalizer::clean('Static IP (192.168.1.5)'));
        // A bare year in parens is not a full date range → kept.
        $this->assertSame('Άδεια (2026)', ItemLabelNormalizer::clean('Άδεια (2026)'));
    }

    public function test_keeps_a_single_date_only_strips_a_range(): void
    {
        // A SINGLE dated one-off is a distinct item — it must NOT fold with a
        // differently-dated sibling, so only a two-date RANGE is stripped.
        $this->assertSame('Event Ticket (15/03/2026)', ItemLabelNormalizer::clean('Event Ticket (15/03/2026)'));
        $this->assertNotSame(
            ItemLabelNormalizer::key('Event Ticket (15/03/2026)'),
            ItemLabelNormalizer::key('Event Ticket (16/03/2026)'),
        );
        // …but a real period range still folds.
        $this->assertSame('Hosting', ItemLabelNormalizer::clean('Hosting (1/1/2026-31/12/2026)'));
    }

    public function test_collapses_whitespace_and_trims(): void
    {
        $this->assertSame('Managed Firewall', ItemLabelNormalizer::clean("  Managed   Firewall  \n"));
    }

    public function test_blank_input_returns_empty(): void
    {
        $this->assertSame('', ItemLabelNormalizer::clean('   '));
        $this->assertSame('', ItemLabelNormalizer::key(''));
    }

    public function test_does_not_touch_interior_date_only_a_trailing_one(): void
    {
        // A date NOT in a trailing parenthetical is left alone (no false grouping).
        $this->assertSame('Άδεια 2026 ετήσια', ItemLabelNormalizer::clean('Άδεια 2026 ετήσια'));
    }
}
