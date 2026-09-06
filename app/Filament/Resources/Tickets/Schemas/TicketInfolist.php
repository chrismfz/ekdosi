<?php

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\InvoiceScope;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The read-only ticket view: a header (status/priority/who/department) + the
 * message thread rendered with a RepeatableEntry (the repo's pattern for a
 * child-record list in an infolist). Internal notes are flagged and coloured so
 * an operator never mistakes one for a customer-visible reply. Replying and
 * status changes are the header actions on ViewTicket.
 */
class TicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Αίτημα')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('reference')->label('Κωδικός')->copyable()->weight('bold'),
                        TextEntry::make('status')->label('Κατάσταση')->badge(),
                        TextEntry::make('priority')->label('Προτεραιότητα')->badge(),
                        TextEntry::make('subject')->label('Θέμα')->columnSpanFull(),
                        TextEntry::make('requester')
                            ->label('Αιτών')
                            ->state(fn (Ticket $record): string => $record->requesterLabel().($record->isGuest() ? ' (GUEST)' : ''))
                            ->badge(fn (Ticket $record): bool => $record->isGuest())
                            ->color(fn (Ticket $record): string => $record->isGuest() ? 'gray' : 'info'),
                        TextEntry::make('department.name')->label('Τμήμα')->placeholder('—'),
                        TextEntry::make('assignee.name')->label('Χειριστής')->placeholder('— χωρίς ανάθεση —'),
                        TextEntry::make('created_at')->label('Ανοίχτηκε')->dateTime('d/m/Y H:i'),
                        TextEntry::make('last_reply_at')->label('Τελευταία απάντηση')->since()->placeholder('—'),
                    ]),

                Section::make('Πελάτης')
                    ->description('Στοιχεία λογαριασμού του αιτούντα — για να απαντάς με την εικόνα του μπροστά σου.')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (Ticket $record): bool => $record->customer_id !== null)
                    ->headerActions([
                        Action::make('kartela')
                            ->label('Άνοιγμα Καρτέλας')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->color('gray')
                            ->url(fn (Ticket $record): ?string => $record->customer_id
                                ? CustomerResource::getUrl('ledger', ['record' => $record->customer_id])
                                : null)
                            ->openUrlInNewTab(),
                    ])
                    ->schema([
                        TextEntry::make('customer.name')->label('Επωνυμία'),
                        TextEntry::make('customer.afm')->label('ΑΦΜ')->placeholder('—'),
                        TextEntry::make('customer.email')->label('Email')->placeholder('—'),
                        TextEntry::make('customer_balance')
                            ->label('Υπόλοιπο (οφειλή)')
                            ->state(fn (Ticket $record): float => self::customerBalance($record))
                            ->money('EUR')
                            ->weight('bold')
                            ->color(fn (Ticket $record): string => self::customerBalance($record) > 0.005 ? 'danger' : 'gray'),
                        RepeatableEntry::make('recent_invoices')
                            ->label('Πρόσφατα παραστατικά (ζωντανά)')
                            ->columnSpanFull()
                            ->columns(4)
                            ->state(fn (Ticket $record): array => self::recentInvoices($record))
                            ->schema([
                                TextEntry::make('code')->hiddenLabel()->weight('bold'),
                                TextEntry::make('issued_at')->hiddenLabel()->color('gray'),
                                TextEntry::make('gross')->hiddenLabel()->money('EUR'),
                                TextEntry::make('status')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => $state ? PaymentStatus::from($state)->label() : '—')
                                    ->color(fn (?string $state): string => $state ? PaymentStatus::from($state)->color() : 'gray'),
                            ]),
                    ]),

                Section::make('Συνομιλία')
                    ->schema([
                        RepeatableEntry::make('messages')
                            ->hiddenLabel()
                            ->columns(2)
                            ->schema([
                                TextEntry::make('author')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->state(fn (TicketMessage $record): string => self::authorLabel($record))
                                    ->color(fn (TicketMessage $record): string => $record->is_internal_note
                                        ? 'warning'
                                        : ($record->isFromOperator() ? 'success' : 'info')),
                                TextEntry::make('created_at')
                                    ->hiddenLabel()
                                    ->since()
                                    ->color('gray')
                                    ->alignEnd(),
                                TextEntry::make('note_flag')
                                    ->hiddenLabel()
                                    ->state(fn (TicketMessage $record): ?string => $record->is_internal_note
                                        ? '🔒 Εσωτερική σημείωση — δεν τη βλέπει ο πελάτης'
                                        : null)
                                    ->color('warning')
                                    ->visible(fn (TicketMessage $record): bool => $record->is_internal_note)
                                    ->columnSpanFull(),
                                TextEntry::make('body')->hiddenLabel()->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    /** @var array<int, float> per-request memo of a ticket's customer outstanding balance */
    private static array $balanceCache = [];

    /**
     * The customer's outstanding balance from the CANONICAL source
     * (Customer::withOutstandingBalance — reconciles with the dashboard/Καρτέλα).
     * Never hand-rolled. Memoised (queried twice per render: value + colour).
     */
    private static function customerBalance(Ticket $ticket): float
    {
        if ($ticket->customer_id === null) {
            return 0.0;
        }

        return self::$balanceCache[(int) $ticket->getKey()] ??= (float) Customer::query()
            ->whereKey($ticket->customer_id)
            ->withOutstandingBalance((int) $ticket->company_id)
            ->value('outstanding_balance');
    }

    /**
     * The customer's most recent LIVE invoices (InvoiceScope::live), reading the
     * canonical per-invoice fields (gross_total + the payment_status cache written
     * only by InvoiceBalance). Read-only, capped — the «Καρτέλα» link has the rest.
     *
     * @return list<array{code:string, issued_at:?string, gross:float, status:?string}>
     */
    private static function recentInvoices(Ticket $ticket): array
    {
        $customer = $ticket->customer;
        if ($customer === null) {
            return [];
        }

        return $customer->invoices()
            ->tap(fn ($query) => InvoiceScope::live($query))
            ->with('invoiceType')
            ->latest('issued_at')
            ->limit(5)
            ->get(['id', 'invoice_type_id', 'code', 'issued_at', 'gross_total', 'payment_status'])
            ->map(fn (Invoice $invoice): array => [
                'code' => trim(($invoice->invoiceType?->code ?? '').($invoice->code ?? '')),
                'issued_at' => $invoice->issued_at?->format('d/m/Y'),
                'gross' => (float) $invoice->gross_total,
                'status' => $invoice->payment_status,
            ])
            ->all();
    }

    private static function authorLabel(TicketMessage $message): string
    {
        return match ($message->author_role) {
            TicketMessage::ROLE_OPERATOR => self::authorName(TicketMessage::ROLE_OPERATOR, $message->author_id) ?? 'Χειριστής',
            TicketMessage::ROLE_CUSTOMER => self::authorName(TicketMessage::ROLE_CUSTOMER, $message->author_id) ?? 'Πελάτης',
            default => 'Σύστημα',
        };
    }

    /**
     * Resolve an author's name, memoised per (role, id) so a thread where the
     * same operator posts many replies costs one query, not one per message
     * (author is role-typed, not an eager-loadable relation).
     *
     * @var array<string, string|null>
     */
    private static array $authorNameCache = [];

    private static function authorName(string $role, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $key = $role.':'.$id;
        if (! array_key_exists($key, self::$authorNameCache)) {
            $model = $role === TicketMessage::ROLE_OPERATOR ? User::find($id) : Customer::find($id);
            self::$authorNameCache[$key] = $model?->name;
        }

        return self::$authorNameCache[$key];
    }
}
