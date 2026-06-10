<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Invoice;
use App\Models\Payment;
use Filament\Resources\Pages\EditRecord;

class EditBankAccount extends EditRecord
{
    protected static string $resource = BankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'τιμολόγια' => Invoice::where('bank_account_id', $record->id)->count(),
                'πληρωμές' => Payment::where('bank_account_id', $record->id)->count(),
            ]),
        ];
    }
}
