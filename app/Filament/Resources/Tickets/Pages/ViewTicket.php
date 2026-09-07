<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Actions\Support\MergeTickets;
use App\Actions\Support\PostTicketMessage;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Jobs\SendTicketFeedbackInvite;
use App\Jobs\SendTicketReplyEmail;
use App\Models\CannedReply;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\CannedReplyExpander;
use App\Support\TicketAttachments;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    /**
     * Eager-load the thread's attachments so the infolist's per-message download
     * links (TicketInfolist::attachmentLinks) don't fire a query per message.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('messages.attachments');
    }

    protected function getHeaderActions(): array
    {
        // Every state-changing action gates on `update` (repo convention, see
        // ViewInvoice): View:Ticket alone must NOT let a read-only viewer reply,
        // reassign, or change status.
        $canUpdate = fn (Ticket $record): bool => auth()->user()?->can('update', $record) ?? false;
        // A merged ticket is terminal — it lives on in its survivor. No mutation of
        // the dead duplicate (a reply would reopen it into an Open-but-merged limbo
        // and never reach the customer, who is redirected to the survivor).
        $notMerged = fn (Ticket $record): bool => ! $record->isMerged();

        return [
            Action::make('reply')
                ->label('Απάντηση')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('primary')
                ->authorize($canUpdate)
                ->visible($notMerged)
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
                    self::attachmentsField(),
                ])
                ->action(function (array $data, Ticket $record): void {
                    $message = app(PostTicketMessage::class)->handle($record, [
                        'author_role' => TicketMessage::ROLE_OPERATOR,
                        'author_id' => auth()->id(),
                        'via' => TicketMessage::VIA_OPERATOR,
                        'body' => $data['body'],
                    ]);
                    self::attachUploaded($message, $data);
                    // Email the reply to the customer (threaded, async), Phase 3b-ii.
                    SendTicketReplyEmail::dispatch($message->id);
                    Notification::make()->title('Η απάντηση καταχωρήθηκε — αποστέλλεται στον πελάτη με email')->success()->send();
                }),

            Action::make('note')
                ->label('Εσωτερική σημείωση')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->authorize($canUpdate)
                ->visible($notMerged)
                ->schema([
                    Textarea::make('body')->label('Σημείωση (μόνο για χειριστές)')->required()->rows(4),
                    self::attachmentsField(),
                ])
                ->action(function (array $data, Ticket $record): void {
                    $message = app(PostTicketMessage::class)->handle($record, [
                        'author_role' => TicketMessage::ROLE_OPERATOR,
                        'author_id' => auth()->id(),
                        'via' => TicketMessage::VIA_OPERATOR,
                        'is_internal_note' => true,
                        'body' => $data['body'],
                    ]);
                    self::attachUploaded($message, $data);
                    Notification::make()->title('Η σημείωση καταχωρήθηκε')->success()->send();
                }),

            Action::make('assign')
                ->label('Ανάθεση')
                ->icon('heroicon-o-user')
                ->color('gray')
                ->authorize($canUpdate)
                ->visible($notMerged)
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
                // hold + close need no explicit isMerged guard: a merged ticket is
                // always Closed (MergeTickets), which their status check already excludes.
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
                    // Feedback-on-close: email a signed rating link (the job re-checks
                    // ratability + recipient, so this is safe to always dispatch).
                    SendTicketFeedbackInvite::dispatch($record->id);
                    Notification::make()->title('Το αίτημα έκλεισε')->success()->send();
                }),

            Action::make('reopen')
                ->label('Επαναφορά')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->authorize($canUpdate)
                // A merged ticket is terminal — it can never reopen.
                ->visible(fn (Ticket $record): bool => $record->status === TicketStatus::Closed && ! $record->isMerged())
                ->action(function (Ticket $record): void {
                    $record->update(['status' => TicketStatus::Open, 'closed_at' => null]);
                    Notification::make()->title('Το αίτημα άνοιξε ξανά')->success()->send();
                }),

            // Merge this (duplicate) ticket INTO another of the SAME customer.
            Action::make('merge')
                ->label('Συγχώνευση')
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('gray')
                ->authorize($canUpdate)
                ->visible(fn (Ticket $record): bool => ! $record->isMerged())
                ->schema([
                    Select::make('target_id')
                        ->label('Συγχώνευση σε αίτημα')
                        ->required()
                        ->searchable()
                        // Initial list = the most recent same-owner tickets; search runs
                        // server-side so an older valid target is still reachable (no 50-cap gap).
                        ->options(fn (Ticket $record): array => self::mergeTargets($record))
                        ->getSearchResultsUsing(fn (string $search, Ticket $record): array => self::mergeTargets($record, $search))
                        ->getOptionLabelUsing(fn ($value, Ticket $record): ?string => self::mergeTargetLabel($record, $value))
                        ->placeholder('— επίλεξε το αίτημα που θα επιβιώσει —')
                        ->helperText('Μόνο αιτήματα του ΙΔΙΟΥ πελάτη. Τα μηνύματα/παραλήπτες μεταφέρονται εκεί· αυτό το αίτημα κλείνει.'),
                ])
                ->requiresConfirmation()
                ->action(function (array $data, Ticket $record, $livewire): void {
                    $target = Ticket::find($data['target_id']);
                    if ($target === null || ! $record->canMergeInto($target)) {
                        Notification::make()->title('Μη έγκυρος στόχος συγχώνευσης')->warning()->send();

                        return;
                    }
                    app(MergeTickets::class)->handle($record, $target);
                    Notification::make()->title('Το αίτημα συγχωνεύθηκε στο '.$target->reference)->success()->send();
                    $livewire->redirect(TicketResource::getUrl('view', ['record' => $target]));
                }),

            // Block the sender (spam/block-sender) — drops ALL their future inbound
            // email (new, reopen, or a reply to an open thread) before it routes.
            // Only when we have an address that wrote in. Needs BOTH ticket-update
            // AND the blocklist create permission (so it can't create a row the
            // operator couldn't otherwise manage).
            Action::make('blockSender')
                ->label('Αποκλεισμός αποστολέα')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->authorize(fn (Ticket $record): bool => ($canUpdate($record))
                    && (auth()->user()?->can('create', TicketBlockedSender::class) ?? false))
                ->visible(fn (Ticket $record): bool => ! $record->isMerged() && filled($record->requester_email))
                ->requiresConfirmation()
                ->modalDescription(fn (Ticket $record): string => 'Ο αποστολέας «'.$record->requester_email
                    .'» δεν θα μπορεί να ανοίγει ή να απαντά αιτήματα μέσω email. Μπορείς να τον αφαιρέσεις από τις Ρυθμίσεις → Υποστήριξη.')
                ->action(function (Ticket $record): void {
                    $pattern = TicketBlockedSender::normalizePattern($record->requester_email);
                    if ($pattern === '' || mb_strlen($pattern) > 254) {
                        Notification::make()->title('Μη έγκυρη διεύθυνση αποστολέα')->warning()->send();

                        return;
                    }
                    $row = TicketBlockedSender::firstOrCreate(
                        ['company_id' => $record->company_id, 'pattern' => $pattern],
                        ['created_by' => auth()->id()],
                    );
                    Notification::make()
                        ->title($row->wasRecentlyCreated ? 'Ο αποστολέας αποκλείστηκε' : 'Ο αποστολέας ήταν ήδη αποκλεισμένος')
                        ->{$row->wasRecentlyCreated ? 'success' : 'info'}()
                        ->send();
                }),

            // Self watch/unwatch (Phase 4): personal — gated on `view`, not `update`.
            Action::make('watch')
                ->label(fn (Ticket $record): string => self::watchesRecord($record) ? 'Διακοπή παρακολούθησης' : 'Παρακολούθηση')
                ->icon(fn (Ticket $record): string => self::watchesRecord($record) ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                ->color('gray')
                ->authorize(fn (Ticket $record): bool => auth()->user()?->can('view', $record) ?? false)
                ->visible($notMerged)
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
                ->visible($notMerged)
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

    /** The shared «Συνημμένα» FileUpload (private disk, allowlisted types, capped). */
    private static function attachmentsField(): FileUpload
    {
        return FileUpload::make('attachments')
            ->label('Συνημμένα')
            ->multiple()
            ->disk(TicketAttachments::DISK)
            ->directory(TicketAttachments::DIRECTORY)
            ->visibility('private')
            ->maxFiles(TicketAttachments::MAX_COUNT)
            ->maxSize(TicketAttachments::MAX_SIZE_KB)
            ->acceptedFileTypes(TicketAttachments::MIME_TYPES)
            // Only files uploaded in THIS session are accepted as final paths — a
            // tampered Livewire submit can't slip in an arbitrary private-disk path
            // (TicketAttachments::fromStoredPaths re-checks the same, defence in depth).
            ->preventFilePathTampering()
            ->storeFileNamesIn('attachment_names')
            ->columnSpanFull();
    }

    /** Persist the FileUpload's stored files as Attachment rows on the message. */
    private static function attachUploaded(TicketMessage $message, array $data): void
    {
        TicketAttachments::fromStoredPaths(
            $message,
            array_values((array) ($data['attachments'] ?? [])),
            (array) ($data['attachment_names'] ?? []),
            auth()->id(),
        );
    }

    /** Does the current operator watch this ticket? (null-user safe.) */
    private static function watchesRecord(Ticket $record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $record->isWatchedBy($user);
    }

    /**
     * Candidate merge targets: other, not-yet-merged tickets of the SAME owner
     * (same customer, or same guest requester email). Auto tenant-scoped by
     * CompanyScope in the panel.
     *
     * @return array<int, string>
     */
    private static function mergeTargets(Ticket $record, ?string $search = null): array
    {
        $query = Ticket::query()
            ->whereKeyNot($record->id)
            ->whereNull('merged_into_id');

        if ($record->customer_id !== null) {
            $query->where('customer_id', $record->customer_id);
        } else {
            $email = mb_strtolower(trim((string) $record->requester_email));
            if ($email === '') {
                return []; // a guest with no address has no matchable sibling
            }
            // LOWER(TRIM(...)) matches Ticket::canMergeInto's trim()+lower — the picker
            // and the safety guard must agree on which guest tickets share an owner.
            $query->whereNull('customer_id')->whereRaw('LOWER(TRIM(requester_email)) = ?', [$email]);
        }

        if (($search = trim((string) $search)) !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $like)->orWhere('subject', 'like', $like));
        }

        return $query->orderByDesc('id')->limit(50)->get()
            ->mapWithKeys(fn (Ticket $t): array => [$t->id => $t->reference.' — '.$t->subject])
            ->all();
    }

    /**
     * Label for a selected merge target (may come from a server-side search, not the
     * initial list). Only a VALID same-owner, not-merged target renders a label — so
     * the picker never presents an ineligible ticket as selectable.
     */
    private static function mergeTargetLabel(Ticket $record, mixed $value): ?string
    {
        $target = Ticket::find($value); // CompanyScope keeps this tenant-local

        return $target !== null && $record->canMergeInto($target)
            ? $target->reference.' — '.$target->subject
            : null;
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
