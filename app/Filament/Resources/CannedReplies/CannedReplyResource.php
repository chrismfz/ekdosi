<?php

namespace App\Filament\Resources\CannedReplies;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\CannedReplies\Pages\CreateCannedReply;
use App\Filament\Resources\CannedReplies\Pages\EditCannedReply;
use App\Filament\Resources\CannedReplies\Pages\ListCannedReplies;
use App\Filament\Resources\CannedReplies\Schemas\CannedReplyForm;
use App\Filament\Resources\CannedReplies\Tables\CannedRepliesTable;
use App\Models\CannedReply;
use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * «Έτοιμες απαντήσεις» (Πυλώνας E) — the predefined replies the operator inserts
 * into a ticket answer, in categories, with {{token}} placeholders. Settings
 * Cluster «Υποστήριξη» sub-section, gated on the pillar.
 */
class CannedReplyResource extends Resource
{
    protected static ?string $model = CannedReply::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Υποστήριξη';

    protected static ?int $navigationSort = 86;

    protected static ?string $modelLabel = 'Έτοιμη απάντηση';

    protected static ?string $pluralModelLabel = 'Έτοιμες απαντήσεις';

    protected static ?string $navigationLabel = 'Έτοιμες απαντήσεις';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasSupport()
            && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return CannedReplyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CannedRepliesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCannedReplies::route('/'),
            'create' => CreateCannedReply::route('/create'),
            'edit' => EditCannedReply::route('/{record}/edit'),
        ];
    }
}
