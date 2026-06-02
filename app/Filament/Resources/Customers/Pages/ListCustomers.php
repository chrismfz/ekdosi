<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Tags\TagControls;
use App\Models\Customer;
use Filament\Actions\CreateAction;
use App\Filament\BaseListRecords;

class ListCustomers extends BaseListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return TagControls::pinnedTabs(Customer::class);
    }
}
