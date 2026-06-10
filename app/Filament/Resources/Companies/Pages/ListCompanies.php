<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Companies\Actions\CompanyBackupActions;
use App\Filament\Resources\Companies\Actions\GlobalSmtpTestAction;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\CreateAction;

class ListCompanies extends BaseListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            // Sits next to «New company» — the create-from-file twin of it.
            CompanyBackupActions::importNew(),
            // Super-admin-only probe of the global .env mailer (tenant-independent).
            GlobalSmtpTestAction::make(),
        ];
    }
}
