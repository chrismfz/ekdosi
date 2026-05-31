<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Services\QuoteNumberer;
use App\Services\QuoteTotals;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    /**
     * Allocate the ΠΡ-{n} code under a row lock inside the same transaction as
     * the INSERT — same atomic discipline as the invoice numberer, on the
     * SEPARATE companies.quote_counter (never the legal ΑΑ).
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            throw new \RuntimeException('Cannot create a quote without a tenant context.');
        }

        return DB::transaction(function () use ($data, $tenant) {
            $data['company_id'] = $tenant->getKey();
            $data['code'] = app(QuoteNumberer::class)->allocate($tenant);

            return Quote::create($data);
        });
    }

    /** Recompute totals once the Repeater has persisted the lines. */
    protected function afterCreate(): void
    {
        app(QuoteTotals::class)($this->record);
    }
}
