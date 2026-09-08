<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\DeliveryNote;
use App\Models\InvoiceType;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Issue a new Δελτίο Αποστολής as a DRAFT.
 *
 * Gapless-at-send (Phase 2, reverses MON-4 for δελτία): a draft no longer consumes
 * an ΑΑ. `code` stays null and the DeliveryNote `created` hook assigns a PROVISIONAL
 * invcode («ΠΡΟΣ-ΔΑΠ-{id}»); the real ΑΑ/invcode/series are allocated at transmission
 * (DeliveryNoteSubmitter → InvoiceNumberer::assignDelivery), so an abandoned or
 * cancelled draft never leaves a gap in the sequence the ΑΑΔΕ sees. The note's
 * mydata_type is still snapshotted from the chosen 9.x type at save.
 *
 * Filing to myDATA is NOT done here — the operator reviews the draft and clicks
 * «Έκδοση» on the view page (mirrors the invoice draft-then-submit flow).
 */
class CreateDeliveryNote extends CreateRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            throw new RuntimeException('Cannot create a delivery note without a tenant context.');
        }

        $type = InvoiceType::query()
            ->where('company_id', $tenant->getKey())
            ->whereKey($data['delivery_type_id'])
            ->firstOrFail();

        // Gapless-at-send: no ΑΑ is consumed at draft creation. `code`/`invcode` are
        // left unset (the `created` hook assigns a provisional invcode); the real
        // number is allocated at transmission by the submitter.
        unset($data['code'], $data['invcode']);
        $data['company_id'] = $tenant->getKey();
        $data['local_status'] = 'draft';
        // Snapshot the myDATA doc type from the chosen delivery type (9.x).
        $data['mydata_type'] = $type->mydata_type;

        return DeliveryNote::create($data);
    }
}
