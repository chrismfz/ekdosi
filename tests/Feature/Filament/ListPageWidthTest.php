<?php

namespace Tests\Feature\Filament;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use Filament\Support\Enums\Width;
use Tests\TestCase;

/**
 * List/table pages opt into full content width (instead of Filament's
 * default 1280px cap) via the shared BaseListRecords base, so wide
 * monitors stop wasting the right margin while the table scrolls.
 */
class ListPageWidthTest extends TestCase
{
    public function test_list_pages_extend_the_shared_full_width_base(): void
    {
        foreach ([ListCustomers::class, ListInvoices::class, ListWhmcsInbox::class] as $page) {
            $this->assertTrue(
                is_subclass_of($page, BaseListRecords::class),
                "{$page} should extend BaseListRecords",
            );
        }
    }

    public function test_full_width_is_applied(): void
    {
        $this->assertSame(Width::Full, (new ListCustomers)->getMaxContentWidth());
    }
}
