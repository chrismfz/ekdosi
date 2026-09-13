<?php

namespace App\Filament\Resources\InboundDeliveryNotes;

use App\Filament\Resources\InboundDeliveryNotes\Pages\ListInboundDeliveryNotes;
use App\Filament\Resources\InboundDeliveryNotes\Pages\ViewInboundDeliveryNote;
use App\Filament\Resources\InboundDeliveryNotes\Schemas\InboundDeliveryNoteInfolist;
use App\Filament\Resources\InboundDeliveryNotes\Tables\InboundDeliveryNotesTable;
use App\Models\InboundDeliveryNote;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Slice 4b — «Εισερχόμενα Διακίνησης»: the operator-facing inbox of ψηφιακή-
 * διακίνηση documents OTHERS filed against us (goods we are receiving), staged by
 * `delivery:fetch-inbound` (Slice 4a). See docs/delivery-inbound-design.md.
 *
 * Read-only list + view (no Create/Edit — operators don't author inbound docs).
 * Rows are acted on via the recipient actions on the view page:
 *   - «Απόρριψη» (RejectDeliveryNote by MARK) — desk-actionable.
 *   - «Έλεγχος κατάστασης» (RequestDeliveryNoteStatus by MARK) — read-only refresh.
 *   - «Παραλήφθηκε» (local-only acknowledge).
 * Confirm-outcome (qrUrl/scan-gated) is Slice 4c — deferred to BACKLOG.
 *
 * Lives in the «Διακίνηση» navigation group, next to the issuer Δελτία. Access is
 * governed by InboundDeliveryNotePolicy; the mutating actions require
 * Update:InboundDeliveryNote (operators hold it via OPERATOR_PERMISSION_MAP).
 */
class InboundDeliveryNoteResource extends Resource
{
    protected static ?string $model = InboundDeliveryNote::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Διακίνηση';

    protected static ?string $navigationLabel = 'Εισερχόμενα Διακίνησης';

    protected static ?string $modelLabel = 'εισερχόμενο διακίνησης';

    protected static ?string $pluralModelLabel = 'Εισερχόμενα Διακίνησης';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'mydata_mark';

    /**
     * Nav badge: how many NEW (unhandled) inbound movements wait for this tenant.
     * Two cheap indexed queries per render (not memoised — stale under Octane).
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return null;
        }

        $count = InboundDeliveryNote::query()
            ->where('company_id', $tenant->getKey())
            ->where('local_state', InboundDeliveryNote::STATE_NEW)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return InboundDeliveryNotesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InboundDeliveryNoteInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInboundDeliveryNotes::route('/'),
            'view' => ViewInboundDeliveryNote::route('/{record}'),
        ];
    }
}
