<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\RelationManagers\ActivityLogRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\RelationManagers\InternalNotesRelationManager;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\InvoicePaymentsRelationManager;
use App\Filament\Resources\Invoices\RelationManagers\MailLogRelationManager;
use App\Filament\Resources\Invoices\RelationManagers\MyDataMarksRelationManager;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Read-only Invoice resource (roadmap step #6).
 *
 * NO create / edit / delete pages — operators can't issue invoices
 * from this resource yet. The issue flow lands in PR #8 once the
 * MyDataSubmitter service (PR #7) is wired. Until then this resource
 * is for browsing what the ETL imported + what will eventually be
 * issued through the IssueInvoice action.
 *
 * Why a separate read-only PR: the underlying schema, models, and
 * presentation patterns are valuable independently of the issue
 * action, and isolating them lets PR #7's architecture decisions
 * (EInvoiceSubmitter interface, provider integration, mydata_type
 * snapshot column) land without pressure to also build UI.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Καθημερινά';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'invcode';

    // Operator-facing UI is Greek; this was the last English nav item.
    protected static ?string $modelLabel = 'παραστατικό';

    protected static ?string $pluralModelLabel = 'Παραστατικά';

    /**
     * Top-bar global search across the invoice code, the snapshotted
     * customer VAT number, and the snapshotted company name. Tenant-
     * scoped via getEloquentQuery() below. Covers "find an invoice by
     * its number or by the customer's AFM".
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['invcode', 'vat_no', 'company_name'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'ΑΦΜ' => $record->vat_no,
            'Ημ/νία' => $record->issued_at?->format('d/m/Y'),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        // Lift SoftDeletingScope on Invoice itself so TrashedFilter
        // works. Also include trashed Customer rows in the eager load
        // so an invoice for a soft-deleted customer still displays the
        // customer name (instead of a blank column / broken Infolist
        // link) — matches the CLAUDE.md application-wide pattern for
        // soft-deleted referenced rows.
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with([
                'customer' => fn ($q) => $q->withTrashed(),
                // The list's «Άτυπο» marker reads the series per row: load it once.
                'invoiceType',
                'latestMailLog',
            ]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        // getEloquentQuery() lifts the SoftDeletingScope so the table's
        // TrashedFilter works — but global search shouldn't surface
        // trashed (cancelled/deleted) invoices as live, badge-less hits.
        return parent::getGlobalSearchEloquentQuery()->whereNull('invoices.deleted_at');
    }

    public static function form(Schema $schema): Schema
    {
        return InvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // «Πληρωμές» first — the invoice LINES now render in the page body
            // (InvoiceInfolist «Γραμμές» section), not as the leading tab.
            InvoicePaymentsRelationManager::class,
            MyDataMarksRelationManager::class,
            InternalNotesRelationManager::class,
            AttachmentsRelationManager::class,
            MailLogRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'edit' => EditInvoice::route('/{record}/edit'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
