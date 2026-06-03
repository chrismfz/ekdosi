<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\BankAccounts\BankAccountResource;
use Filament\Actions\CreateAction;

class ListBankAccounts extends BaseListRecords
{
    protected static string $resource = BankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
