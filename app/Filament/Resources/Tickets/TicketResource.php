<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\TicketStatus;
use App\Filament\Clusters\SupportCluster;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\Schemas\TicketForm;
use App\Filament\Resources\Tickets\Schemas\TicketInfolist;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Models\Company;
use App\Models\Ticket;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Operator ticket UI (Πυλώνας E) — lives in the «Υποστήριξη» Support Cluster,
 * visible only for tenants with the pillar enabled. List/create/view + the
 * reply/status header actions on the view page. Editing a ticket is by action,
 * not a form (a ticket isn't a form record), so there is no edit page.
 */
class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $cluster = SupportCluster::class;

    protected static ?string $modelLabel = 'αίτημα';

    protected static ?string $pluralModelLabel = 'Αιτήματα';

    protected static ?string $navigationLabel = 'Αιτήματα';

    protected static ?string $recordTitleAttribute = 'subject';

    /** Only for tenants with the Support pillar enabled. */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasSupport()
            && parent::canAccess();
    }

    /** The count of tickets waiting on an operator — a live queue signal on the nav. */
    public static function getNavigationBadge(): ?string
    {
        $count = Ticket::query()->whereIn('status', TicketStatus::queueValues())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return TicketForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TicketInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TicketsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
