<?php

namespace App\Filament\Resources\TicketBlockedSenders;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\TicketBlockedSenders\Pages\CreateTicketBlockedSender;
use App\Filament\Resources\TicketBlockedSenders\Pages\ListTicketBlockedSenders;
use App\Filament\Resources\TicketBlockedSenders\Schemas\TicketBlockedSenderForm;
use App\Filament\Resources\TicketBlockedSenders\Tables\TicketBlockedSendersTable;
use App\Models\Company;
use App\Models\TicketBlockedSender;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * «Αποκλεισμένοι αποστολείς» (Πυλώνας E) — the per-tenant spam blocklist the
 * inbound router checks before opening a ticket. Settings Cluster «Υποστήριξη»
 * sub-section, gated on the pillar.
 */
class TicketBlockedSenderResource extends Resource
{
    protected static ?string $model = TicketBlockedSender::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Υποστήριξη';

    protected static ?int $navigationSort = 87;

    protected static ?string $modelLabel = 'Αποκλεισμένος αποστολέας';

    protected static ?string $pluralModelLabel = 'Αποκλεισμένοι αποστολείς';

    protected static ?string $navigationLabel = 'Αποκλεισμένοι αποστολείς';

    protected static ?string $recordTitleAttribute = 'pattern';

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasSupport()
            && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return TicketBlockedSenderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TicketBlockedSendersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTicketBlockedSenders::route('/'),
            'create' => CreateTicketBlockedSender::route('/create'),
        ];
    }
}
