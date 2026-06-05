<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Filament\Pages\MyDataMarkDetail;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Full audit trail of every myDATA INSERT / CANCEL submission for
 * this invoice. Read-only — the rows are written by the future
 * MyDataSubmitter service (PR #7); operators only view them here.
 *
 * Two "View XML" actions surface the raw request and response from
 * AADE in modals — important for legal audit and for diagnosing
 * rejections. The XML is stored verbatim per the schema (request
 * + response mediumText columns).
 */
class MyDataMarksRelationManager extends RelationManager
{
    protected static string $relationship = 'mydataMarks';

    protected static ?string $title = 'Ιστορικό υποβολών (myDATA / Πάροχος)';

    protected static ?string $recordTitleAttribute = 'mark';

    public function form(Schema $schema): Schema
    {
        // Read-only RelationManager; form is required by the contract
        // but never used.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('mydata_action')
                    ->label('Action')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'INSERT', 'PROVIDER_INSERT' => 'success',  // real filing, MARK issued (direct or via provider)
                        'CANCEL', 'PROVIDER_CANCEL' => 'danger',   // real cancellation, MARK preserved
                        'REJECTED', 'PROVIDER_REJECTED' => 'danger', // refused (null mark, response XML kept)
                        'CANCEL_REJECTED' => 'danger', // AADE refused the cancellation (null mark, response kept; state NOT flipped)
                        'DRY_RUN' => 'info',    // preview from "Preview submission XML"
                        'STATE_SYNC' => 'warning',  // operator synced local state from AADE truth
                        'SKIPPED', 'SKIPPED_CANCEL' => 'warning',  // NullSubmitter: deliberate non-filing
                        default => 'gray',
                    }),

                TextColumn::make('provider_key')
                    ->label('Πάροχος')
                    ->placeholder('—')  // null for direct myDATA filings
                    ->toggleable(),

                TextColumn::make('authentication_code')
                    ->label('Auth code')
                    ->limit(12)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('mark')
                    ->label('MARK')
                    ->placeholder('—')  // SKIPPED + DRY_RUN rows have null mark
                    ->copyable()
                    // Link the MARK to its full detail page (header + lines +
                    // XML). Null marks (DRY_RUN / SKIPPED) stay plain text.
                    ->color(fn ($record) => $record->mark ? 'primary' : null)
                    ->url(fn ($record) => $record->mark
                        ? MyDataMarkDetail::getUrl(['mark' => $record->mark, 'tenant' => Filament::getTenant()])
                        : null),

                TextColumn::make('mark_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                TextColumn::make('mark_time')
                    ->label('Time')
                    ->time('H:i:s')
                    ->placeholder('—'),

                TextColumn::make('invoice_url')
                    ->label('QR URL')
                    ->url(fn (?string $state) => $state)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view_request_xml')
                    ->label('Request XML')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn ($record) => [
                        Textarea::make('request_xml')
                            ->label(false)
                            ->default($record->request)
                            ->rows(20)
                            ->columnSpanFull()
                            ->readOnly(),
                    ])
                    ->modalHeading(fn ($record) => 'Request XML — MARK '.$record->mark),

                Action::make('view_response_xml')
                    ->label('Response XML')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn ($record) => [
                        Textarea::make('response_xml')
                            ->label(false)
                            ->default($record->response)
                            ->rows(20)
                            ->columnSpanFull()
                            ->readOnly(),
                    ])
                    ->modalHeading(fn ($record) => 'Response XML — MARK '.$record->mark),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
