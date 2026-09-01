<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\DeliveryNote;
use App\Models\InvoiceType;
use App\Services\InvoiceNumberer;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issue a new Δελτίο Αποστολής as a DRAFT. The ΑΑ is allocated server-side by
 * InvoiceNumberer under a row lock inside the same transaction as the INSERT —
 * IDENTICAL atomic semantics to CreateInvoice (the legacy INVOICE_BI1 +
 * INVOICE_AI triggers). The delivery type's series feeds the counter; the
 * note's mydata_type is snapshotted from the type at save.
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

        return DB::transaction(function () use ($data, $tenant, $type) {
            // allowMovementType: a Δελτίο Αποστολής legitimately carries a 9.x
            // type, so it opts out of the numberer's monetary 9.x guard (MYD-003).
            $allocation = app(InvoiceNumberer::class)->allocate($tenant, $type->code, allowMovementType: true);

            $data['code'] = $allocation->code;
            $data['invcode'] = $allocation->invcode;
            $data['company_id'] = $tenant->getKey();
            $data['local_status'] = 'draft';
            // Snapshot the myDATA doc type from the chosen delivery type (9.x).
            $data['mydata_type'] = $type->mydata_type;

            return DeliveryNote::create($data);
        });
    }
}
