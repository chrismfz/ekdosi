<?php

namespace App\Filament\Resources\FirebirdImportRuns;

use App\Filament\Resources\FirebirdImportRuns\Pages\CreateFirebirdImportRun;
use App\Filament\Resources\FirebirdImportRuns\Pages\ListFirebirdImportRuns;
use App\Filament\Resources\FirebirdImportRuns\Pages\ViewFirebirdImportRun;
use App\Filament\Resources\FirebirdImportRuns\Schemas\FirebirdImportRunForm;
use App\Filament\Resources\FirebirdImportRuns\Schemas\FirebirdImportRunInfolist;
use App\Filament\Resources\FirebirdImportRuns\Tables\FirebirdImportRunsTable;
use App\Models\FirebirdImportRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * PR #30 — Firebird Import history + new-import flow.
 *
 * Three pages: List (history table + "New Import" button), Create
 * (file upload form), View (read-only run details).
 *
 * No Edit page — runs are immutable audit trail. A "bad" run is
 * documented by creating a new run, not by editing the old one.
 *
 * Lives in the "Data" navigation group alongside the eventual
 * cutover-runbook tools.
 */
class FirebirdImportRunResource extends Resource
{
    protected static ?string $model = FirebirdImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Firebird Import';

    protected static ?string $modelLabel = 'Firebird import';

    protected static ?string $pluralModelLabel = 'Firebird imports';

    protected static ?int $navigationSort = 90;

    protected static ?string $recordTitleAttribute = 'file_name';

    public static function form(Schema $schema): Schema
    {
        return FirebirdImportRunForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FirebirdImportRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FirebirdImportRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListFirebirdImportRuns::route('/'),
            'create' => CreateFirebirdImportRun::route('/create'),
            'view'   => ViewFirebirdImportRun::route('/{record}'),
        ];
    }
}
