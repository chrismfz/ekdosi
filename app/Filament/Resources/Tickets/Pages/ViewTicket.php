<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Actions\Support\PostTicketMessage;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Jobs\SendTicketReplyEmail;
use App\Models\CannedReply;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\CannedReplyExpander;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        // Every state-changing action gates on `update` (repo convention, see
        // ViewInvoice): View:Ticket alone must NOT let a read-only viewer reply,
        // reassign, or change status.
        $canUpdate = fn (Ticket $record): bool => auth()->user()?->can('update', $record) ?? false;

        return [
            Action::make('reply')
                ->label('Απάντηση')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('primary')
                ->authorize($canUpdate)
                ->schema([
                    Select::make('canned')
                        ->label('Έτοιμη απάντηση')
                        ->placeholder('— προαιρετικά: συμπλήρωσε από έτοιμη —')
                        ->options(fn (): array => self::cannedOptions())
                        ->searchable()
                        ->live()
                        ->dehydrated(false)
                        ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire): void {
                            if (blank($state)) {
                                return;
                            }
                            $reply = CannedReply::find($state);
                            $ticket = $livewire->getRecord();
                            if ($reply && $ticket instanceof Ticket) {
                                $expanded = CannedReplyExpander::expand($reply->body, $ticket, auth()->user());
                                // Append (don't clobber) anything the operator already typed.
                                $existing = trim((string) $get('body'));
                                $set('body', $existing === '' ? $expanded : $existing."\n\n".$expanded);
                            }
                        }),
                    Textarea::make('body')->label('Απάντηση προς τον πελάτη')->required()->rows(6),
                ])
                ->action(function (array $data, Ticket $record): void {
                    $message = app(PostTicketMessage::class)->handle($record, [
                        'author_role' => TicketMessage::ROLE_OPERATOR,
                        'author_id' => auth()->id(),
                        'via' => TicketMessage::VIA_OPERATOR,
                        'body' => $data['body'],
                    ]);
                    // Email the reply to the customer (threaded, async), Phase 3b-ii.
                    SendTicketReplyEmail::dispatch($message->id);
                    Notification::make()->title('Η απάντηση καταχωρήθηκε — αποστέλλεται στον πελάτη με email')->success()->send();
                }),

            Action::make('note')
                ->label('Εσωτερική σημείωση')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->authorize($canUpdate)
                ->schema([
                    Textarea::make('body')->label('Σημείωση (μόνο για χειριστές)')->required()->rows(4),
                ])
                ->action(function (array $data, Ticket $record): void {
                    app(PostTicketMessage::class)->handle($record, [
                        'author_role' => TicketMessage::ROLE_OPERATOR,
                        'author_id' => auth()->id(),
                        'via' => TicketMessage::VIA_OPERATOR,
                        'is_internal_note' => true,
                        'body' => $data['body'],
                    ]);
                    Notification::make()->title('Η σημείωση καταχωρήθηκε')->success()->send();
                }),

            Action::make('assign')
                ->label('Ανάθεση')
                ->icon('heroicon-o-user')
                ->color('gray')
                ->authorize($canUpdate)
                ->schema([
                    Select::make('assigned_to')
                        ->label('Χειριστής')
                        ->options(fn (): array => TicketResource::operatorOptions())
                        ->default(fn (Ticket $record): ?int => $record->assigned_to ?? auth()->id())
                        ->searchable()
                        ->placeholder('— χωρίς ανάθεση —'),
                ])
                ->action(function (array $data, Ticket $record): void {
                    $record->update(['assigned_to' => $data['assigned_to'] ?: null]);
                    Notification::make()->title('Η ανάθεση ενημερώθηκε')->success()->send();
                }),

            Action::make('hold')
                ->label('Σε αναμονή')
                ->icon('heroicon-o-pause-circle')
                ->color('gray')
                ->authorize($canUpdate)
                ->visible(fn (Ticket $record): bool => ! in_array($record->status, [TicketStatus::Closed, TicketStatus::OnHold], true))
                ->action(function (Ticket $record): void {
                    $record->update(['status' => TicketStatus::OnHold]);
                    Notification::make()->title('Το αίτημα μπήκε σε αναμονή')->success()->send();
                }),

            Action::make('close')
                ->label('Κλείσιμο')
                ->icon('heroicon-o-check-circle')
                ->color('gray')
                ->authorize($canUpdate)
                ->visible(fn (Ticket $record): bool => $record->status !== TicketStatus::Closed)
                ->requiresConfirmation()
                ->action(function (Ticket $record): void {
                    $record->update(['status' => TicketStatus::Closed, 'closed_at' => now()]);
                    Notification::make()->title('Το αίτημα έκλεισε')->success()->send();
                }),

            Action::make('reopen')
                ->label('Επαναφορά')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->authorize($canUpdate)
                ->visible(fn (Ticket $record): bool => $record->status === TicketStatus::Closed)
                ->action(function (Ticket $record): void {
                    $record->update(['status' => TicketStatus::Open, 'closed_at' => null]);
                    Notification::make()->title('Το αίτημα άνοιξε ξανά')->success()->send();
                }),

            // Self watch/unwatch (Phase 4): personal — gated on `view`, not `update`.
            Action::make('watch')
                ->label(fn (Ticket $record): string => self::watchesRecord($record) ? 'Διακοπή παρακολούθησης' : 'Παρακολούθηση')
                ->icon(fn (Ticket $record): string => self::watchesRecord($record) ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                ->color('gray')
                ->authorize(fn (Ticket $record): bool => auth()->user()?->can('view', $record) ?? false)
                ->action(function (Ticket $record): void {
                    $user = auth()->user();
                    if (! $user instanceof User) {
                        return;
                    }
                    if ($record->isWatchedBy($user)) {
                        $record->unwatch($user);
                        Notification::make()->title('Σταμάτησες να παρακολουθείς το αίτημα')->success()->send();
                    } else {
                        $record->watch($user);
                        Notification::make()->title('Παρακολουθείς το αίτημα')->success()->send();
                    }
                }),

            Action::make('addWatcher')
                ->label('Προσθήκη watcher')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->authorize($canUpdate)
                ->schema([
                    Select::make('user_id')
                        ->label('Χειριστής')
                        ->options(fn (): array => TicketResource::operatorOptions())
                        ->searchable()
                        ->placeholder('— χειριστής που θα ειδοποιείται —'),
                    TextInput::make('email')
                        ->label('ή email (κοινοποίηση στις απαντήσεις, κρυφό Bcc)')
                        ->email()
                        ->placeholder('someone@example.com'),
                ])
                ->action(function (array $data, Ticket $record): void {
                    $resolved = false; // a valid operator/email was supplied
                    $created = false;  // a NEW watcher row was actually written
                    // Resolve the operator WITHIN the ticket's tenant — never a raw
                    // User::find (a tampered submit could otherwise attach a foreign
                    // user and leak this ticket's subject to them via the bell).
                    if (! empty($data['user_id'])) {
                        $operator = $record->company?->users()->whereKey($data['user_id'])->first();
                        if ($operator !== null) {
                            $resolved = true;
                            $created = $record->watch($operator)->wasRecentlyCreated || $created;
                        }
                    }
                    if (! empty($data['email'])) {
                        $watcher = $record->addEmailWatcher($data['email']);
                        if ($watcher !== null) {
                            $resolved = true;
                            $created = $watcher->wasRecentlyCreated || $created;
                        }
                    }

                    [$title, $type] = match (true) {
                        $created => ['Προστέθηκε watcher', 'success'],
                        $resolved => ['Παρακολουθεί ήδη', 'info'],
                        default => ['Δώσε χειριστή ή email', 'warning'],
                    };
                    Notification::make()->title($title)->{$type}()->send();
                }),
        ];
    }

    /** Does the current operator watch this ticket? (null-user safe.) */
    private static function watchesRecord(Ticket $record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $record->isWatchedBy($user);
    }

    /**
     * The tenant's active canned replies as grouped Select options (category =>
     * [id => title]). Auto tenant-scoped by CompanyScope in the panel.
     *
     * @return array<string, array<int, string>>
     */
    private static function cannedOptions(): array
    {
        $out = [];
        CannedReply::query()
            ->where('is_active', true)
            ->with('category')
            ->orderBy('sort')
            ->orderBy('title')
            ->get(['id', 'title', 'canned_reply_category_id'])
            ->each(function (CannedReply $reply) use (&$out): void {
                $out[$reply->category?->name ?? 'Γενικά'][$reply->id] = $reply->title;
            });

        return $out;
    }
}
