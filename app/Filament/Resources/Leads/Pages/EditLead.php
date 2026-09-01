<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\ConvertLeadToCustomer;
use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Support\PickerOptions;
use App\Models\Customer;
use App\Models\Lead;
use App\Services\Leads\LeadMatcher;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;
use WeakMap;

/**
 * Lead edit page = the lead's «cockpit»: the form on top, the Χρονολόγιο
 * (quick-add calls/emails/meetings) and the other tabs below.
 */
class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // «Μετατροπή σε πελάτη» — the ONLY way a lead becomes Won. Two paths:
            // a NEW customer copied from the lead, or a LINK to the existing
            // customer the dedupe found (never a duplicate legal party).
            Action::make('convert')
                ->label('Μετατροπή σε πελάτη')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->visible(fn (Lead $record): bool => ! $record->isConverted() && ! $record->trashed())
                ->modalHeading('Μετατροπή σε πελάτη')
                ->modalDescription('Το lead γίνεται «Πελάτης» και συνδέεται με τον πελάτη — ο πελάτης «θυμάται» από πού ήρθε. Σημειώσεις/συνημμένα μένουν στο lead, οι προσφορές του περνούν στον πελάτη.')
                ->modalSubmitActionLabel('Μετατροπή')
                ->schema([
                    Radio::make('mode')
                        ->label('Πώς')
                        ->options([
                            'new' => 'Νέος πελάτης (αντιγραφή στοιχείων από το lead)',
                            'link' => 'Σύνδεση με υπάρχοντα πελάτη',
                        ])
                        ->default(fn (Lead $record): string => self::matchingCustomerId($record) ? 'link' : 'new')
                        ->required()
                        ->live(),

                    Select::make('customer_id')
                        ->label('Υπάρχων πελάτης')
                        ->searchable()
                        ->options(fn (): array => PickerOptions::favouriteCustomerOptions())
                        ->getSearchResultsUsing(fn (string $search): array => PickerOptions::searchCustomerOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => Customer::query()
                            ->where('company_id', $this->record->company_id)
                            ->find($value)?->name)
                        ->default(fn (Lead $record): ?int => self::matchingCustomerId($record))
                        ->visible(fn (callable $get): bool => $get('mode') === 'link')
                        ->required(fn (callable $get): bool => $get('mode') === 'link')
                        ->helperText('Προεπιλέγεται ο πελάτης που ταιριάζει σε ΑΦΜ/email/τηλέφωνο, αν υπάρχει.'),
                ])
                ->action(function (Lead $record, array $data): void {
                    $existing = null;
                    if (($data['mode'] ?? 'new') === 'link') {
                        $existing = Customer::query()
                            ->where('company_id', $record->company_id)
                            ->find($data['customer_id'] ?? null);
                        if ($existing === null) {
                            Notification::make()->title('Διάλεξε υπάρχοντα πελάτη.')->danger()->send();

                            return;
                        }
                    }

                    try {
                        $customer = app(ConvertLeadToCustomer::class)($record, $existing);
                    } catch (Throwable $e) {
                        Notification::make()->title('Η μετατροπή απέτυχε')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title($existing ? 'Συνδέθηκε με τον πελάτη' : 'Δημιουργήθηκε πελάτης')
                        ->body($customer->name.' — από lead #'.$record->id)
                        ->success()
                        ->send();

                    $this->redirect(CustomerResource::getUrl('edit', ['record' => $customer]));
                }),

            // After conversion: one click to the customer.
            Action::make('openCustomer')
                ->label(fn (Lead $record): string => 'Πελάτης: '.($record->customer?->name ?? '#'.$record->converted_customer_id))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('success')
                ->visible(fn (Lead $record): bool => $record->isConverted())
                ->url(fn (Lead $record): ?string => $record->customer
                    ? CustomerResource::getUrl('edit', ['record' => $record->customer])
                    : null),

            // «Νέα προσφορά» — opens the quote form pre-filled from the lead.
            Action::make('newQuote')
                ->label('Νέα προσφορά')
                ->icon('heroicon-o-document-text')
                ->color('warning')
                ->visible(fn (Lead $record): bool => ! $record->isConverted() && ! $record->trashed())
                ->url(fn (Lead $record): string => QuoteResource::getUrl('create', ['lead' => $record->id])),

            // Αλλαγή κατάστασης — one modal, any → any (Won excluded: only the
            // conversion action writes it). Lost / DoNotContact need a reason;
            // the status hook on the model turns the change into a timeline row.
            Action::make('changeStatus')
                ->label('Αλλαγή κατάστασης')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (Lead $record): bool => $record->status !== LeadStatus::Won)
                ->modalHeading('Αλλαγή κατάστασης')
                ->modalSubmitActionLabel('Αποθήκευση')
                ->schema([
                    Select::make('status')
                        ->label('Νέα κατάσταση')
                        ->options(fn (Lead $record): array => collect(LeadStatus::options())
                            ->except($record->status?->value)
                            ->all())
                        ->required()
                        ->live(),

                    Textarea::make('reason')
                        ->label(fn (callable $get): string => self::requiresReason($get('status'))
                            ? 'Λόγος (υποχρεωτικό)'
                            : 'Σχόλιο (προαιρετικό — μπαίνει στο Χρονολόγιο)')
                        ->rows(2)
                        // Lands in leads.lost_reason (varchar 255) for lost/dnc.
                        ->maxLength(255)
                        ->required(fn (callable $get): bool => self::requiresReason($get('status'))),
                ])
                ->action(function (Lead $record, array $data): void {
                    $status = LeadStatus::from($data['status']);
                    $comment = trim((string) ($data['reason'] ?? ''));

                    if ($status === LeadStatus::DoNotContact && $record->status !== LeadStatus::DoNotContact) {
                        // Terminal-ish: make sure it was deliberate.
                        Notification::make()
                            ->title('Σημειώθηκε «Μην ξαναενοχλήσετε»')
                            ->body('Το lead θα εμφανίζεται με κόκκινη προειδοποίηση σε κάθε νέα καταχώριση με το ίδιο ΑΦΜ/email/τηλέφωνο.')
                            ->warning()
                            ->send();
                    }

                    // The reason belongs to the lost/dnc state only — clear it on
                    // the way out so a re-opened lead doesn't carry «λόγος: …».
                    $record->update([
                        'status' => $status,
                        'lost_reason' => $status->requiresReason() ? $comment : null,
                    ]);

                    // A free comment on a non-lost change is worth a note row too.
                    if ($comment !== '' && ! $status->requiresReason()) {
                        $record->timeline()->create([
                            'company_id' => $record->company_id,
                            'user_id' => auth()->id(),
                            'type' => LeadActivityType::Note->value,
                            'happened_at' => now(),
                            'body' => $comment,
                        ]);
                    }

                    Notification::make()
                        ->title('Κατάσταση: '.$status->getLabel())
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'lost_reason']);
                }),

            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /**
     * The form hides `lost_reason` for non-lost statuses (so it never
     * dehydrates) — null it here so a stale reason can't survive a save.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! self::requiresReason($data['status'] ?? null)) {
            $data['lost_reason'] = null;
        }

        return $data;
    }

    /**
     * Memo keyed by the record INSTANCE (WeakMap: dies with the object, so it
     * can't leak across requests/workers or tests) — the modal asks twice
     * (Radio + Select defaults) and the matcher runs two REPLACE()-heavy scans.
     *
     * @var WeakMap<Lead, int|null>|null
     */
    private static ?WeakMap $matchMemo = null;

    /** The customer the dedupe matcher points at (ΑΦΜ / email / phone), if any. */
    private static function matchingCustomerId(Lead $record): ?int
    {
        self::$matchMemo ??= new WeakMap;

        if (isset(self::$matchMemo[$record])) {
            return self::$matchMemo[$record];
        }

        $match = app(LeadMatcher::class)->find(
            $record->company_id,
            $record->afm,
            $record->email,
            [$record->phone, $record->mobile],
            $record->id,
        );

        return self::$matchMemo[$record] = $match->customers->first()?->id;
    }

    private static function requiresReason(mixed $status): bool
    {
        return LeadStatus::tryFrom((string) $status)?->requiresReason() ?? false;
    }
}
