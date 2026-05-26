<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Invoices\RelationManagers\MyDataMarksRelationManager;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'invcode';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
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
            LinesRelationManager::class,
            MyDataMarksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
