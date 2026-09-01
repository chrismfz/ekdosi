<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

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

    private static function requiresReason(mixed $status): bool
    {
        return LeadStatus::tryFrom((string) $status)?->requiresReason() ?? false;
    }
}
