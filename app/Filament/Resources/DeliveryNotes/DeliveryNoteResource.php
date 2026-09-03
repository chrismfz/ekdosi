<?php

namespace App\Filament\Resources\DeliveryNotes;

use App\Filament\Resources\DeliveryNotes\Pages\CreateDeliveryNote;
use App\Filament\Resources\DeliveryNotes\Pages\EditDeliveryNote;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Filament\RelationManagers\ActivityLogRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\RelationManagers\InternalNotesRelationManager;
use App\Filament\Resources\DeliveryNotes\RelationManagers\DeliveryEventsRelationManager;
use App\Filament\Resources\DeliveryNotes\RelationManagers\DeliveryMarksRelationManager;
use App\Filament\Resources\DeliveryNotes\RelationManagers\LinesRelationManager;
use App\Filament\Resources\DeliveryNotes\Schemas\DeliveryNoteForm;
use App\Filament\Resources\DeliveryNotes\Schemas\DeliveryNoteInfolist;
use App\Filament\Resources\DeliveryNotes\Tables\DeliveryNotesTable;
use App\Models\Company;
use App\Models\DeliveryNote;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Παραστατικά Διακίνησης (Δελτία Αποστολής / Ψηφιακή Διακίνηση) — D2 part 3.
 *
 * A value-less twin of the invoice resource: same numbering (InvoiceNumberer)
 * and the same draft→VALID issue lifecycle, but it carries no money/VAT (its
 * own table, never in InvoiceScope). The «Έκδοση» action files the note via
 * DeliveryNoteSubmitter (SendInvoices, 9.x type). The e-transport lifecycle
 * (RegisterTransfer / ConfirmDeliveryOutcome) is D3, a separate task.
 *
 * Admin-gated like Reports/LedgerBook (View:DeliveryNote permission + a Company
 * tenant). Gate::can is 404-storm-safe — a missing permission returns false,
 * not a PermissionDoesNotExist throw, so the resource is silent until
 * shield:generate has created the permission.
 */
class DeliveryNoteResource extends Resource
{
    protected static ?string $model = DeliveryNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Ψηφιακή Διακίνηση';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'invcode';

    public static function getNavigationLabel(): string
    {
        return 'Παραστατικά Διακίνησης';
    }

    public static function getModelLabel(): string
    {
        return 'Δελτίο Αποστολής';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Παραστατικά Διακίνησης';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:DeliveryNote');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['invcode', 'recipient_name', 'recipient_afm'];
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryNoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeliveryNoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            DeliveryEventsRelationManager::class,
            DeliveryMarksRelationManager::class,
            InternalNotesRelationManager::class,
            AttachmentsRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryNotes::route('/'),
            'create' => CreateDeliveryNote::route('/create'),
            'view' => ViewDeliveryNote::route('/{record}'),
            'edit' => EditDeliveryNote::route('/{record}/edit'),
        ];
    }
}
