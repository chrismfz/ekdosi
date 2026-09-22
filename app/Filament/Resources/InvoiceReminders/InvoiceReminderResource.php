<?php

namespace App\Filament\Resources\InvoiceReminders;

use App\Filament\Resources\InvoiceReminders\Pages\ListInvoiceReminders;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Jobs\SendInvoiceReminder;
use App\Models\InvoiceReminder;
use App\Services\Reminders\ReminderMessage;
use App\Services\Reminders\ReminderPlanner;
use App\Services\Reminders\ReminderSettings;
use App\Support\CustomerLanguage;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * «Υπενθυμίσεις» — every payment reminder: what went out, what failed, what was
 * skipped or cancelled (and why), and — in review mode — what waits for the
 * operator's «Αποστολή». Rows are created by the daily run (ReminderRunner);
 * the operator only sends, skips or re-sends. Tenant-scoped (BelongsToCompany).
 */
class InvoiceReminderResource extends Resource
{
    protected static ?string $model = InvoiceReminder::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?string $navigationLabel = 'Υπενθυμίσεις';

    protected static ?string $modelLabel = 'υπενθύμιση';

    protected static ?string $pluralModelLabel = 'Υπενθυμίσεις πληρωμής';

    protected static ?int $navigationSort = 31;

    public static function getNavigationBadge(): ?string
    {
        $awaiting = InvoiceReminder::query()->where('status', InvoiceReminder::STATUS_AWAITING)->count();

        return $awaiting > 0 ? (string) $awaiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['invoice.customer', 'customer', 'triggeredBy']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => InvoiceReminder::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        InvoiceReminder::STATUS_SENT => 'success',
                        InvoiceReminder::STATUS_FAILED => 'danger',
                        InvoiceReminder::STATUS_AWAITING => 'warning',
                        InvoiceReminder::STATUS_QUEUED, InvoiceReminder::STATUS_SENDING => 'info',
                        default => 'gray',
                    })
                    ->description(fn (InvoiceReminder $r): ?string => $r->reason),
                TextColumn::make('stage')
                    ->label('Βαθμίδα')
                    ->badge()
                    ->color(fn (string $state): string => $state === InvoiceReminder::STAGE_FINAL ? 'danger' : 'gray')
                    ->formatStateUsing(fn (string $state): string => InvoiceReminder::STAGE_LABELS[$state] ?? $state),
                TextColumn::make('invoice.invcode')
                    ->label('Παραστατικό')
                    ->searchable()
                    ->url(fn (InvoiceReminder $r): ?string => $r->invoice ? InvoiceResource::getUrl('view', ['record' => $r->invoice]) : null)
                    ->description(fn (InvoiceReminder $r): ?string => $r->document_kind === InvoiceReminder::KIND_PROFORMA ? 'Προτιμολόγιο' : null),
                TextColumn::make('customer.name')
                    ->label('Πελάτης')
                    ->searchable()
                    ->limit(30)
                    ->placeholder('—'),
                TextColumn::make('due_date')
                    ->label('Λήξη')
                    ->date('d/m/Y')
                    ->description(fn (InvoiceReminder $r): ?string => match (true) {
                        $r->days_overdue === null => null,
                        $r->days_overdue < 0 => 'σε '.abs($r->days_overdue).' ημ.',
                        default => $r->days_overdue.' ημ. εκπρόθεσμο',
                    }),
                TextColumn::make('balance')
                    ->label('Υπόλοιπο')
                    ->money('EUR', locale: 'el')
                    ->alignEnd(),
                TextColumn::make('recipient')
                    ->label('Προς')
                    ->placeholder('—')
                    ->limit(28)
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->label('Εστάλη')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Καταγράφηκε')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('triggeredBy.name')
                    ->label('Από')
                    ->placeholder('Σύστημα')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('stage')->label('Βαθμίδα')->options(InvoiceReminder::STAGE_LABELS),
                SelectFilter::make('document_kind')->label('Είδος')->options([
                    InvoiceReminder::KIND_INVOICE => 'Τιμολόγιο',
                    InvoiceReminder::KIND_PROFORMA => 'Προτιμολόγιο',
                ]),
            ])
            ->recordActions([
                self::sendAction(),
                self::previewAction(),
                Action::make('skip')
                    ->label('Παράλειψη')
                    ->icon('heroicon-o-no-symbol')
                    ->color('gray')
                    ->visible(fn (InvoiceReminder $r): bool => $r->status === InvoiceReminder::STATUS_AWAITING)
                    ->authorize(fn (InvoiceReminder $r): bool => auth()->user()?->can('update', $r) ?? false)
                    ->requiresConfirmation()
                    ->action(function (InvoiceReminder $r): void {
                        self::skip($r)
                            ? Notification::make()->title('Η υπενθύμιση παραλείφθηκε')->success()->send()
                            : Notification::make()->title('Δεν παραλείφθηκε — η κατάστασή της άλλαξε στο μεταξύ.')->warning()->send();
                    }),
                Action::make('view_error')
                    ->label('Σφάλμα')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn (InvoiceReminder $r): bool => filled($r->error_message))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Κλείσιμο')
                    ->modalHeading('Αποτυχία αποστολής')
                    ->schema(fn (InvoiceReminder $r): array => [
                        Textarea::make('error_message')->label(false)->default($r->error_message)->rows(8)->readOnly(),
                    ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('send_selected')
                        ->label('Αποστολή επιλεγμένων')
                        ->icon('heroicon-o-paper-airplane')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $sent = $records->filter(fn (InvoiceReminder $r): bool => (auth()->user()?->can('update', $r) ?? false) && self::queue($r))
                                ->count();
                            Notification::make()->title("Στάλθηκαν στην ουρά: {$sent}")->success()->send();
                        }),
                    BulkAction::make('skip_selected')
                        ->label('Παράλειψη επιλεγμένων')
                        ->icon('heroicon-o-no-symbol')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $skipped = $records->filter(fn (InvoiceReminder $r): bool => (auth()->user()?->can('update', $r) ?? false) && self::skip($r))
                                ->count();
                            Notification::make()->title("Παραλείφθηκαν: {$skipped}")->success()->send();
                        }),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    private static function sendAction(): Action
    {
        return Action::make('send')
            ->label(fn (InvoiceReminder $r): string => $r->status === InvoiceReminder::STATUS_FAILED ? 'Ξανά αποστολή' : 'Αποστολή')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->visible(fn (InvoiceReminder $r): bool => $r->isSendable())
            ->authorize(fn (InvoiceReminder $r): bool => auth()->user()?->can('update', $r) ?? false)
            ->requiresConfirmation()
            ->modalDescription(fn (InvoiceReminder $r): string => 'Θα σταλεί email στον πελάτη «'.($r->customer?->name ?? '—').'». Αν στο μεταξύ εξοφλήθηκε, η υπενθύμιση ακυρώνεται αυτόματα.')
            ->action(function (InvoiceReminder $r): void {
                self::queue($r);
                Notification::make()->title('Η υπενθύμιση μπήκε στην ουρά αποστολής')->success()->send();
            });
    }

    /** What the customer would receive if it went out now (current balance, current templates). */
    private static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Προεπισκόπηση')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (InvoiceReminder $r): bool => $r->isSendable() && $r->invoice !== null)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Κλείσιμο')
            ->modalHeading('Προεπισκόπηση email')
            ->modalContent(function (InvoiceReminder $r): HtmlString {
                $invoice = $r->invoice->loadMissing(['customer', 'company', 'paymentMethod', 'invoiceType']);
                $due = ReminderPlanner::dueDateOf($invoice);
                $message = app(ReminderMessage::class)->build(
                    $invoice,
                    $r->stage,
                    $due,
                    $due !== null ? (int) $due->diffInDays(CarbonImmutable::today(), false) : null,
                    $invoice->balanceData()->balance,
                    ReminderSettings::for($invoice->company),
                    CustomerLanguage::forDocumentMail($invoice),
                );

                return new HtmlString(
                    '<p><strong>Προς:</strong> '.e((string) $invoice->customer?->email).'</p>'
                    .'<p style="margin-top:.5rem"><strong>Θέμα:</strong> '.e($message['subject']).'</p>'
                    .'<div style="margin-top:.75rem;white-space:pre-wrap">'.e($message['bodyText']).'</div>'
                );
            });
    }

    /**
     * Hand a row to the sender — only if it is still awaiting/failed (a conditional
     * update, so a stale screen can't re-queue a sent row); the sender then claims
     * it, so even two dispatches can never email twice.
     */
    public static function queue(InvoiceReminder $r): bool
    {
        $updated = InvoiceReminder::query()
            ->whereKey($r->getKey())
            ->whereIn('status', [InvoiceReminder::STATUS_AWAITING, InvoiceReminder::STATUS_FAILED])
            ->update(['status' => InvoiceReminder::STATUS_QUEUED, 'triggered_by_user_id' => auth()->id(), 'updated_at' => now()]);

        if ($updated > 0) {
            SendInvoiceReminder::dispatch($r->getKey());
        }

        return $updated > 0;
    }

    /** Skip a row still «προς έγκριση» — conditional, like queue(): a row sent meanwhile stays sent. */
    public static function skip(InvoiceReminder $r): bool
    {
        return InvoiceReminder::query()
            ->whereKey($r->getKey())
            ->where('status', InvoiceReminder::STATUS_AWAITING)
            ->update([
                'status' => InvoiceReminder::STATUS_SKIPPED,
                'reason' => 'Παράλειψη από '.(auth()->user()?->name ?? 'χειριστή').'.',
                'triggered_by_user_id' => auth()->id(),
                'updated_at' => now(),
            ]) > 0;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceReminders::route('/'),
        ];
    }
}
