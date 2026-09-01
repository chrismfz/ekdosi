<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Lead;
use App\Models\Quote;
use App\Services\QuoteNumberer;
use App\Services\QuoteTotals;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    /**
     * Full-width content so the Excel-style lines table uses the whole screen
     * (the default centred container squeezed the columns).
     */
    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

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

    /**
     * Leads L1: opened as «Νέα προσφορά» from a lead (`?lead=ID`) → pre-fill the
     * party snapshot from the lead and carry `lead_id`. Tenant-checked; an
     * unknown / foreign id is simply ignored.
     */
    protected function afterFill(): void
    {
        $lead = $this->leadFromRequest();
        if ($lead === null) {
            return;
        }

        $this->form->fill(array_merge($this->data ?? [], [
            'lead_id' => $lead->id,
            'company_name' => $lead->name,
            'vat_no' => $lead->afm,
            'occupation' => $lead->occupation,
            'address1' => $lead->address1,
            'city' => $lead->city,
            'postcode' => $lead->postcode,
            'country' => $lead->country ?: 'GR',
        ]));
    }

    private function leadFromRequest(): ?Lead
    {
        $id = (int) request()->query('lead', 0);
        if ($id <= 0) {
            return null;
        }

        return Lead::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->find($id);
    }

    /** Recompute totals once the Repeater has persisted the lines; log on the lead. */
    protected function afterCreate(): void
    {
        app(QuoteTotals::class)($this->record);

        if ($this->record->lead_id !== null) {
            $lead = Lead::query()
                ->where('company_id', $this->record->company_id)
                ->find($this->record->lead_id);
            $lead?->recordQuote($this->record);
        }
    }
}
