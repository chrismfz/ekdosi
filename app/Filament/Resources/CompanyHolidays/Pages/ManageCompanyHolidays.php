<?php

namespace App\Filament\Resources\CompanyHolidays\Pages;

use App\Filament\Resources\CompanyHolidays\CompanyHolidayResource;
use App\Support\Hr\GreekHolidays;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageCompanyHolidays extends ManageRecords
{
    protected static string $resource = CompanyHolidayResource::class;

    public function getSubheading(): ?string
    {
        $year = (int) now()->format('Y');
        $national = collect(GreekHolidays::forYear($year))
            ->map(fn (string $name, string $date): string => CarbonImmutable::parse($date)->format('d/m').' '.$name)
            ->implode(' · ');

        return "Οι εθνικές αργίες υπολογίζονται αυτόματα ({$year}: {$national}). Εδώ μόνο όσες ΕΠΙΠΛΕΟΝ τηρεί το γραφείο.";
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    $data['company_id'] ??= Filament::getTenant()?->getKey();

                    return $data;
                }),
        ];
    }
}
