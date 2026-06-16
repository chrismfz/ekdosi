<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Tags\TagControls;
use App\Models\Invoice;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends BaseListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Νέο Παραστατικό'),
        ];
    }

    public function getTabs(): array
    {
        $outbox = Invoice::query()->awaitingMyData()->count();

        return [
            'all' => Tab::make('Όλα'),

            // The myDATA «Outbox»: live παραστατικά that should be filed but carry
            // no MARK (drafts + failed/skipped submissions). One click to the
            // worklist of «τι μένει να υποβληθεί». Badge only when there's work.
            'outbox' => Tab::make('Προς υποβολή')
                ->icon('heroicon-o-cloud-arrow-up')
                ->badge($outbox ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingMyData()),
        ] + TagControls::tagTabs(Invoice::class);
    }
}
