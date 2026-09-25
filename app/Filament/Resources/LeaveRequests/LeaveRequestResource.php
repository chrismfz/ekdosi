<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveRequests\Pages\ViewLeaveRequest;
use App\Filament\Resources\LeaveRequests\Schemas\LeaveRequestForm;
use App\Filament\Resources\LeaveRequests\Schemas\LeaveRequestInfolist;
use App\Filament\Resources\LeaveRequests\Tables\LeaveRequestsTable;
use App\Models\LeaveRequest;
use App\Policies\LeaveRequestPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «Άδειες» — leave requests (docs/ergani/README.md §4). Staff (operator /
 * ergani role) see and file ONLY their own; approvers (Update:LeaveRequest)
 * see everyone's and decide. No edit page: a decided leave is what the
 * accountant was told — change it by cancelling and filing a new one.
 */
class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Άδειες';

    protected static ?string $modelLabel = 'άδεια';

    protected static ?string $pluralModelLabel = 'Άδειες';

    protected static ?int $navigationSort = 10;

    protected static bool $isGloballySearchable = false;

    /** Non-approvers only ever query their own employee record's requests. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee', 'decidedBy', 'company']);

        if (! LeaveRequestPolicy::isApprover(auth()->user())) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', auth()->id() ?? 0));
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! LeaveRequestPolicy::isApprover(auth()->user())) {
            return null;
        }

        $pending = static::getEloquentQuery()->where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return LeaveRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeaveRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveRequests::route('/'),
            'create' => CreateLeaveRequest::route('/create'),
            'view' => ViewLeaveRequest::route('/{record}'),
        ];
    }
}
