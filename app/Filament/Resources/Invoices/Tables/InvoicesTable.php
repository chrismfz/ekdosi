<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\LocalStatus;
use App\Enums\PaymentStatus;
use App\Filament\Pages\MyDataMarkDetail;
use App\Filament\Support\Tags\TagControls;
use App\Jobs\SendInvoiceEmail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\EInvoiceSubmitterFactory;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invcode')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    // Informal (non-fiscal) series — visibly not a tax document.
                    ->description(fn (Invoice $record): ?string => $record->isInformal() ? 'Άτυπο' : null),

                TextColumn::make('issued_at')
                    ->label('Issued')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('invoiceType.code')
                    ->label('Series')
                    ->toggleable(),

                TextColumn::make('invoiceType.name')
                    ->label('Type')
                    ->toggleable()
                    ->limit(40),

                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('gross_total')
                    ->label('Total')
                    ->money('EUR')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('local_status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? LocalStatus::from($state)->label() : '—')
                    ->color(fn (?string $state) => $state ? LocalStatus::from($state)->color() : 'gray')
                    ->sortable(),

                TextColumn::make('payment_status')
                    ->label('Πληρωμή')
                    ->badge()
                    ->placeholder('—')
                    // Cancelled invoices aren't receivables — show a
                    // neutral dash (the Κατάσταση column already says
                    // Ακυρωμένο). Credit notes show "Πιστωτικό". Otherwise
                    // the computed payment status.
                    ->formatStateUsing(fn (?string $state, $record) => match (true) {
                        $record->local_status === 'cancelled' => '—',
                        $record->credited_invoice_id !== null => 'Πιστωτικό',
                        (bool) $state => PaymentStatus::from($state)->label(),
                        default => '—',
                    })
                    ->color(fn (?string $state, $record) => match (true) {
                        $record->local_status === 'cancelled' => 'gray',
                        $record->credited_invoice_id !== null => 'info',
                        (bool) $state => PaymentStatus::from($state)->color(),
                        default => 'gray',
                    })
                    ->toggleable(),

                // Λήξη (due date) — only meaningful for credit-term invoices;
                // turns red + «Ληξιπρόθεσμο» once past due with an open balance.
                TextColumn::make('due_date')
                    ->label('Λήξη')
                    ->badge()
                    ->placeholder('—')
                    ->state(fn (Invoice $record) => $record->dueDate()?->format('d/m/Y'))
                    ->description(fn (Invoice $record) => $record->isOverdue() ? 'Ληξιπρόθεσμο' : null)
                    ->color(fn (Invoice $record) => $record->isOverdue() ? 'danger' : 'gray')
                    ->toggleable(),

                TextColumn::make('mydata_state')
                    ->label('myDATA')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'VALID' => 'success',
                        'CANCELLED' => 'danger',
                        null => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state) => $state ?? 'pending')
                    ->toggleable(),

                TextColumn::make('mydata_mark')
                    ->label('MARK')
                    ->placeholder('—')
                    // Click the MARK → full «Έλεγχος ΜΑΡΚ» page (was just
                    // copyable-to-itself, the operator's complaint).
                    ->color(fn (Invoice $record) => filled($record->mydata_mark) ? 'primary' : null)
                    ->url(fn (Invoice $record) => filled($record->mydata_mark)
                        ? MyDataMarkDetail::getUrl(['mark' => $record->mydata_mark, 'tenant' => Filament::getTenant()])
                        : null)
                    ->toggleable(),

                // Last email attempt at a glance — so a 'failed' send is visible
                // in the list (filter below) without opening each invoice.
                TextColumn::make('email_status')
                    ->label('Email')
                    ->badge()
                    ->state(fn (?Invoice $record): ?string => $record?->latestMailLog?->status)
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'sent' => 'Στάλθηκε',
                        'failed' => 'Απέτυχε',
                        'queued' => 'Σε ουρά',
                        'sending' => 'Αποστολή…',
                        default => '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'queued', 'sending' => 'info',
                        default => 'gray',
                    })
                    ->tooltip(fn (?Invoice $record): ?string => $record?->latestMailLog?->status === 'failed'
                        ? $record->latestMailLog->error_message
                        : null)
                    ->toggleable(),

                TextColumn::make('paymentMethod.description')
                    ->label('Payment')
                    ->toggleable(isToggledHiddenByDefault: true),

                TagControls::column(),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('invoice_type_id')
                    ->label('Invoice type')
                    ->options(fn () => InvoiceType::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($t) => [$t->id => $t->code.' — '.$t->name])
                        ->toArray())
                    ->searchable(),

                SelectFilter::make('customer_id')
                    ->label('Customer')
                    ->searchable()
                    // withTrashed() so the filter can resolve labels for
                    // invoices that reference a soft-deleted customer
                    // — otherwise historical invoices for departed
                    // customers become unfilterable.
                    ->getSearchResultsUsing(fn (string $search) => Customer::query()
                        ->withTrashed()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->where('name', 'like', "%{$search}%")
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => $c->trashed() ? $c->name.' (deleted)' : $c->name])
                        ->toArray())
                    ->getOptionLabelUsing(fn ($value) => (function () use ($value) {
                        $c = Customer::query()
                            ->withTrashed()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->whereKey($value)
                            ->first();

                        return $c ? ($c->trashed() ? $c->name.' (deleted)' : $c->name) : null;
                    })()),

                SelectFilter::make('payment_status')
                    ->label('Κατάσταση πληρωμής')
                    ->options(collect(PaymentStatus::cases())
                        ->mapWithKeys(fn (PaymentStatus $s) => [$s->value => $s->label()])
                        ->toArray())
                    ->placeholder('All'),

                Filter::make('overdue')
                    ->label('Μόνο ληξιπρόθεσμα')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->overdue()),

                // «Προτιμολόγια»: offered drafts, split by whether the money has
                // arrived. They are invisible to «Απαιτήσεις» by design (an unpaid
                // proforma owes nothing yet), so this is how an operator finds them —
                // and the paid ones are the queue still waiting to be ISSUED.
                SelectFilter::make('proforma')
                    ->label('Προτιμολόγια')
                    ->options([
                        'all' => 'Όλα τα προτιμολόγια',
                        'unpaid' => 'Απλήρωτα',
                        'paid' => 'Πληρωμένα (προς έκδοση)',
                    ])
                    ->query(function ($query, array $data) {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $query->where('invoices.local_status', 'draft')
                            ->whereNotNull('invoices.offered_at');

                        $hasPayment = fn ($q) => $q->from('payments')
                            ->whereColumn('payments.invoice_id', 'invoices.id')
                            ->whereNull('payments.deleted_at');

                        return match ($data['value']) {
                            'paid' => $query->whereExists($hasPayment),
                            'unpaid' => $query->whereNotExists($hasPayment),
                            default => $query,
                        };
                    }),

                SelectFilter::make('local_status')
                    ->label('Κατάσταση')
                    ->options(collect(LocalStatus::cases())
                        ->mapWithKeys(fn (LocalStatus $s) => [$s->value => $s->label()])
                        ->toArray())
                    ->placeholder('Όλες'),

                SelectFilter::make('mydata_state')
                    ->label('myDATA state')
                    ->options([
                        'VALID' => 'VALID (filed)',
                        'CANCELLED' => 'CANCELLED',
                    ])
                    ->placeholder('All'),

                TernaryFilter::make('mydata_sent')
                    ->label('Submitted to myDATA')
                    ->placeholder('All')
                    ->trueLabel('Submitted only')
                    ->falseLabel('Not submitted only'),

                // Surface invoices whose email needs attention. "Απέτυχε" =
                // the LATEST send attempt failed (a later success supersedes it).
                SelectFilter::make('mail_status')
                    ->label('Κατάσταση email')
                    ->options([
                        'failed' => 'Απέτυχε',
                        'sent' => 'Στάλθηκε',
                        'pending' => 'Σε ουρά / αποστολή',
                        'none' => 'Χωρίς αποστολή',
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        $v = $data['value'] ?? null;
                        if ($v === null || $v === '') {
                            return $q;
                        }
                        if ($v === 'none') {
                            return $q->whereDoesntHave('mailLog');
                        }

                        return $q->whereLatestMailStatus($v === 'pending' ? ['queued', 'sending'] : [$v]);
                    }),

                // Quick period presets so nothing unsent slips past the
                // weekly review (e.g. "Αυτό το τρίμηνο" + "Μη υποβληθέντα").
                SelectFilter::make('period')
                    ->label('Περίοδος')
                    ->options([
                        'week' => 'Αυτή την εβδομάδα',
                        'month' => 'Αυτόν τον μήνα',
                        'last_month' => 'Προηγούμενος μήνας',
                        'quarter' => 'Αυτό το τρίμηνο',
                        'year' => 'Φέτος',
                    ])
                    ->query(function (Builder $q, array $data) {
                        $v = $data['value'] ?? null;
                        if (! $v) {
                            return $q;
                        }
                        $now = now();
                        [$from, $to] = match ($v) {
                            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
                            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
                            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
                            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
                            default => [null, null],
                        };

                        return $q->when($from, fn ($q) => $q->whereBetween('issued_at', [$from, $to]));
                    }),

                TagControls::filter(),

                Filter::make('issued_at_range')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Issued from'),
                        DatePicker::make('to')
                            ->label('Issued to'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('issued_at', '>=', $d))
                            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('issued_at', '<=', $d));
                    }),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),

                // Edit is offered ONLY on an unissued draft — the one state the
                // EditInvoice form (and its lines repeater) actually accepts. A
                // finalised, filed, or cancelled invoice is legally frozen, so the
                // page refuses it anyway; without this gate the pencil would just
                // bounce the operator with an error. This is the sole in-app entry
                // point to the full edit environment (date / type / lines / price),
                // so it must exist here and mirror EditInvoice::mount()'s predicate
                // exactly.
                EditAction::make()
                    ->visible(fn (Invoice $record) => $record->mydata_state === null
                        && $record->local_status === 'draft'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Weekly batch-file: filter (e.g. Ενεργό + Μη
                    // υποβληθέντα + period) → select → submit. Only
                    // un-filed, non-credit-note rows are sent; the rest
                    // are skipped. Each submit is an independent AADE
                    // call (per-row error handling, partial success OK).
                    BulkAction::make('submit_mydata')
                        ->label('Υποβολή επιλεγμένων στο myDATA')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->visible(fn () => in_array(
                            Filament::getTenant()?->mydata_mode,
                            ['sandbox', 'production'],
                            true,
                        ))
                        ->requiresConfirmation()
                        ->modalHeading('Μαζική υποβολή στο myDATA')
                        ->modalDescription('Υποβάλλονται μόνο τα μη υποβληθέντα (χωρίς MARK). Πιστωτικά, άτυπα και ήδη υποβληθέντα παραλείπονται.')
                        ->action(function (Collection $records) {
                            $ok = 0;
                            $skip = 0;
                            $fail = 0;
                            $submitter = app(EInvoiceSubmitterFactory::class)->for(Filament::getTenant());

                            foreach ($records as $record) {
                                // Skip already-filed, credit notes, AND
                                // locally-cancelled rows — never file a doc
                                // the operator voided (the default filter
                                // shows ALL, so a cancelled row can be in
                                // the selection). local_status draft→active
                                // is synced inside the submitter.
                                if ($record->mydata_state !== null
                                    || $record->credited_invoice_id !== null
                                    || $record->local_status === 'cancelled'
                                    // An informal series is never filed — by design, not a failure.
                                    || $record->isInformal()) {
                                    $skip++;

                                    continue;
                                }
                                // PROV-019: the per-row soft-warn modal can't be shown
                                // in a bulk run, so at least keep the same durable trace
                                // the single-submit action leaves — a replacement whose
                                // reversed original is still standing at AADE files
                                // double-turnover, and this must stay answerable later.
                                if ($record->replacementReversalPending()) {
                                    Log::warning('PROV-019: bulk-filing a replacement while its reversed original is still standing at AADE', [
                                        'company_id' => $record->company_id,
                                        'replacement' => $record->invcode,
                                        'original' => $record->reissuedFrom?->invcode,
                                        'original_id' => $record->reissued_from_invoice_id,
                                    ]);
                                }
                                try {
                                    $submitter->submit($record);
                                    $ok++;
                                } catch (Throwable $e) {
                                    $fail++;
                                }
                            }

                            Notification::make()
                                ->title("Υποβλήθηκαν: {$ok} · Παραλείφθηκαν: {$skip} · Απέτυχαν: {$fail}")
                                ->{$fail > 0 ? 'warning' : 'success'}()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Re-send the invoice email for the selection (same as the
                    // per-invoice "Email PDF" action). Pair with the «Κατάσταση
                    // email = Απέτυχε» filter to clear failures from the web.
                    BulkAction::make('resend_email')
                        ->label('Επαναποστολή email')
                        ->icon('heroicon-o-envelope')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Επαναποστολή email στους πελάτες')
                        ->modalDescription('Μπαίνει στην ουρά ένα email με το τρέχον PDF για κάθε επιλεγμένο τιμολόγιο. Παραλείπονται όσα δεν έχουν email πελάτη ή δεν είναι εκδοθέντα (πρόχειρα/ακυρωμένα).')
                        ->action(function (Collection $records): void {
                            $queued = 0;
                            $skipNoEmail = 0;
                            $skipNotIssued = 0;
                            foreach ($records as $record) {
                                // DOC-6: never bulk-email a draft or cancelled
                                // document — the body claims it was issued. Same
                                // gate as the per-invoice action + public route.
                                if (! $record->isPubliclyViewable()) {
                                    $skipNotIssued++;

                                    continue;
                                }
                                if (blank($record->customer?->email)) {
                                    $skipNoEmail++;

                                    continue;
                                }
                                SendInvoiceEmail::dispatch($record, trigger: 'manual', triggeredByUserId: auth()->id());
                                $queued++;
                            }

                            $skipped = $skipNoEmail + $skipNotIssued;
                            Notification::make()
                                ->title("Στην ουρά: {$queued} · Παραλείφθηκαν: {$skipped} (χωρίς email: {$skipNoEmail}, μη εκδοθέντα: {$skipNotIssued})")
                                ->{$skipped > 0 ? 'warning' : 'success'}()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Tag any selection — the way to tag FILED invoices, which
                    // can't be edited through the form.
                    TagControls::bulkAttachAction(),
                ]),
            ])
            ->defaultSort('issued_at', 'desc');
    }
}
