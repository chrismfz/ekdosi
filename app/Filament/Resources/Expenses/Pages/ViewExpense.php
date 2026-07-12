<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\ExpenseClassificationRule;
use App\Services\MyData\ExpenseClassificationSubmitter;
use App\Support\MyData\Codes;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Unified expense classification (E5). LOCAL — one action, two modes:
            // «Ενιαίος» = one §8.x E3 type + category2_x (εμπορεύματα/πάγια/δαπάνες)
            // for the whole doc; «Μικτό» = a per-line type+category (a supplier
            // invoice may mix εμπόρευμα + πάγιο + δαπάνη). Stored locally, then
            // submitted to AADE via «Υποβολή». The submitter prefers each line's
            // value, falling back to the header — so both modes file correctly.
            Action::make('classify')
                ->label('Χαρακτηρισμός')
                ->icon('heroicon-o-tag')
                ->color('primary')
                ->modalHeading('Χαρακτηρισμός εξόδου')
                ->modalDescription(fn (): string => $this->record->classification_state === 'submitted'
                    ? '⚠ Έχει ΗΔΗ υποβληθεί χαρακτηρισμός στην ΑΑΔΕ. Νέος χαρακτηρισμός απαιτεί ΕΠΑΝΥΠΟΒΟΛΗ (η προηγούμενη υποβολή διατηρείται στο ιστορικό).'
                    : 'Διάλεξε «Ενιαίος» για όλο το παραστατικό, ή «Μικτό» για διαφορετικό χαρακτηρισμό ανά γραμμή. Αποθηκεύεται τοπικά — υπόβαλέ το με το «Υποβολή χαρακτηρισμού».')
                ->modalSubmitActionLabel('Αποθήκευση')
                ->fillForm(fn (): array => [
                    'mode' => $this->record->classificationIsMixed() ? 'mixed' : 'single',
                    'classification_type' => $this->record->classification_type,
                    'classification_category' => $this->record->classification_category,
                    'lines' => $this->record->lines->map(fn ($line): array => [
                        'id' => $line->id,
                        'item_descr' => $line->item_descr,
                        'net_value' => $line->net_value,
                        'classification_type' => $line->classification_type ?: $this->record->classification_type,
                        'classification_category' => $line->classification_category ?: $this->record->classification_category,
                    ])->all(),
                ])
                ->schema([
                    Radio::make('mode')
                        ->label('Τρόπος χαρακτηρισμού')
                        ->options($this->classificationModeOptions())
                        ->default('single')
                        ->live()
                        ->required(),

                    // «Ενιαίος» — one classification for the whole document.
                    Select::make('classification_type')
                        ->label('Τύπος χαρακτηρισμού (E3)')
                        ->options(Codes::expenseClassTypeOptions())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('mode') !== 'mixed')
                        ->required(fn (Get $get): bool => $get('mode') !== 'mixed'),
                    Select::make('classification_category')
                        ->label('Κατηγορία χαρακτηρισμού')
                        ->options(Codes::expenseClassCategoryOptions())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('mode') !== 'mixed')
                        ->required(fn (Get $get): bool => $get('mode') !== 'mixed'),

                    // «Μικτό» — a type+category per line.
                    Repeater::make('lines')
                        ->label('Γραμμές')
                        ->visible(fn (Get $get): bool => $get('mode') === 'mixed')
                        ->addable(false)->deletable(false)->reorderable(false)
                        ->itemLabel(fn (array $state): string => trim((string) ($state['item_descr'] ?? '—'))
                            .' · '.number_format((float) ($state['net_value'] ?? 0), 2).'€')
                        ->schema([
                            Hidden::make('id'),
                            Select::make('classification_type')
                                ->label('Τύπος (E3)')
                                ->options(Codes::expenseClassTypeOptions())
                                ->searchable()->required(),
                            Select::make('classification_category')
                                ->label('Κατηγορία')
                                ->options(Codes::expenseClassCategoryOptions())
                                ->searchable()->required(),
                        ])
                        ->columns(2),
                ])
                ->action(function (array $data): void {
                    // Atomic: the per-line writes + the header/state write must land
                    // together (a crash mid-way must not leave lines cleared while
                    // the header stays unclassified).
                    DB::transaction(function () use ($data): void {
                        if (($data['mode'] ?? 'single') === 'mixed') {
                            $rows = collect($data['lines'] ?? [])->keyBy('id');
                            foreach ($this->record->lines as $line) {
                                $row = $rows->get($line->id);
                                if ($row === null) {
                                    continue;
                                }
                                $line->forceFill([
                                    'classification_type' => $row['classification_type'],
                                    'classification_category' => $row['classification_category'],
                                ])->save();
                            }
                            $this->record->forceFill(['classification_state' => 'classified'])->save();
                        } else {
                            // Single: clear any per-line overrides so the header is the
                            // one effective classification, then stamp header + state.
                            $this->record->lines()->update([
                                'classification_type' => null,
                                'classification_category' => null,
                            ]);
                            $this->record->forceFill([
                                'classification_type' => $data['classification_type'],
                                'classification_category' => $data['classification_category'],
                                'classification_state' => 'classified',
                            ])->save();
                        }
                    });

                    Notification::make()
                        ->title('Ο χαρακτηρισμός αποθηκεύτηκε')
                        ->body('Υπόβαλέ τον στην ΑΑΔΕ με το «Υποβολή χαρακτηρισμού».')
                        ->success()
                        ->send();
                }),

            // Build a reusable auto-classification rule (#5) from THIS expense, so
            // the next doc from the same supplier classifies itself. Prefills the
            // supplier ΑΦΜ + the current classification; the operator picks whether
            // it's supplier-wide or only for this type.
            Action::make('create_rule')
                ->label('Δημιουργία κανόνα')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->supplier_afm)
                    && $this->record->company?->einvoice_provider === 'gr-mydata')
                ->modalHeading('Κανόνας αυτόματου χαρακτηρισμού')
                ->modalDescription(fn (): string => 'Τα επόμενα έξοδα από τον προμηθευτή «'
                    .($this->record->supplier_name ?: $this->record->supplier_afm).'» θα χαρακτηρίζονται αυτόματα.')
                ->modalSubmitActionLabel('Δημιουργία')
                ->fillForm(fn (): array => [
                    'classification_type' => $this->record->classification_type,
                    'classification_category' => $this->record->classification_category,
                    'only_this_type' => false,
                ])
                ->schema([
                    Select::make('classification_type')
                        ->label('Τύπος χαρακτηρισμού (E3)')
                        ->options(Codes::expenseClassTypeOptions())
                        ->searchable()
                        ->required(),
                    Select::make('classification_category')
                        ->label('Κατηγορία χαρακτηρισμού')
                        ->options(Codes::expenseClassCategoryOptions())
                        ->searchable()
                        ->required(),
                    Toggle::make('only_this_type')
                        ->label(fn (): string => 'Μόνο για τον τύπο '.($this->record->invoice_type ?: '—'))
                        ->visible(fn (): bool => filled($this->record->invoice_type))
                        ->helperText('Αλλιώς ο κανόνας ισχύει για όλους τους τύπους αυτού του προμηθευτή.'),
                ])
                ->action(function (array $data): void {
                    ExpenseClassificationRule::create([
                        'company_id' => $this->record->company_id,
                        'supplier_afm' => $this->record->supplier_afm,
                        'invoice_type' => ($data['only_this_type'] ?? false) ? $this->record->invoice_type : null,
                        'classification_type' => $data['classification_type'],
                        'classification_category' => $data['classification_category'],
                        'label' => $this->record->supplier_name,
                        'is_active' => true,
                    ]);

                    Notification::make()
                        ->title('Ο κανόνας δημιουργήθηκε')
                        ->body('Θα εφαρμόζεται αυτόματα στα επόμενα έξοδα του προμηθευτή.')
                        ->success()
                        ->send();
                }),

            // Submit the local classification to AADE (SendExpensesClassification).
            // Visible only for a myDATA-pulled doc (has a ΜΑΡΚ) that is classified
            // but not yet submitted.
            Action::make('submit_classification')
                ->label('Υποβολή χαρακτηρισμού')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (): bool => filled($this->record->mydata_mark)
                    && $this->record->classification_state === 'classified')
                ->requiresConfirmation()
                ->modalHeading('Υποβολή χαρακτηρισμού στην ΑΑΔΕ')
                ->modalDescription('Στέλνει τον χαρακτηρισμό (τύπος E3 + κατηγορία) του παραστατικού στη myDATA. Μη αναστρέψιμο μέσω της εφαρμογής.')
                ->action(function (): void {
                    $tenant = $this->record->company ?? Filament::getTenant();

                    try {
                        $mark = app()->makeWith(ExpenseClassificationSubmitter::class, ['tenant' => $tenant])
                            ->submit($this->record);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Αποτυχία υποβολής')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Ο χαρακτηρισμός υποβλήθηκε στην ΑΑΔΕ')
                        ->body($mark !== '' ? 'ΜΑΡΚ χαρακτηρισμού: '.$mark : 'Επιτυχία.')
                        ->success()
                        ->send();
                }),

            // Operator notes (σημειώσεις) — a targeted write of ONLY the `notes`
            // column, available on EVERY expense including the read-only
            // myDATA-sourced ones (whose full Edit is blocked by canEdit to keep
            // the AADE mirror intact). This is how you annotate a doc — e.g.
            // «αυτό είναι εισιτήριο Aegean» — without touching the mirrored data.
            Action::make('notes')
                ->label('Σημειώσεις')
                ->icon('heroicon-o-chat-bubble-bottom-center-text')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('Update:Expense'))
                ->modalHeading('Σημειώσεις εξόδου')
                ->modalDescription('Ιδιωτικές σημειώσεις του χειριστή για αυτό το παραστατικό. Δεν αποστέλλονται στην ΑΑΔΕ.')
                ->modalSubmitActionLabel('Αποθήκευση')
                ->fillForm(fn (): array => ['notes' => $this->record->notes])
                ->schema([
                    Textarea::make('notes')
                        ->label('Σημειώσεις')
                        ->rows(4)
                        ->maxLength(65535)
                        ->placeholder('π.χ. Εισιτήριο Aegean — μετακίνηση για…'),
                ])
                ->action(function (array $data): void {
                    // forceFill: only the notes column moves; the AADE-mirrored
                    // header/totals/state are never touched here.
                    $this->record->forceFill(['notes' => $data['notes'] ?: null])->save();

                    Notification::make()->title('Οι σημειώσεις αποθηκεύτηκαν')->success()->send();
                }),

            // Download the attached private document over a short-lived signed
            // route (streamed from disk, never a public URL).
            Action::make('download_document')
                ->label('Λήψη παραστατικού')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->document_path))
                ->url(fn (): string => URL::temporarySignedRoute(
                    'expenses.document.download',
                    now()->addMinutes(5),
                    ['expense' => $this->record],
                ), shouldOpenInNewTab: true),

            // Edit — only for MANUAL expenses (the resource's canEdit gate).
            EditAction::make(),
        ];
    }

    /**
     * Classification-mode options: «Ενιαίος» always; «Μικτό» only when there are
     * 2+ lines to mix (a single-line doc can't be mixed).
     *
     * @return array<string, string>
     */
    private function classificationModeOptions(): array
    {
        $options = ['single' => 'Ενιαίος (όλο το παραστατικό)'];
        if ($this->record->lines()->count() > 1) {
            $options['mixed'] = 'Μικτό (διαφορετικός ανά γραμμή)';
        }

        return $options;
    }
}
