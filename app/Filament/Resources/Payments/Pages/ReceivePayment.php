<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentAllocator;
use App\Support\Money;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Url;

/**
 * «Νέα είσπραξη» — the receipt cockpit. One screen instead of «find the customer,
 * open the Καρτέλα, open a modal, pick invoices one by one»:
 *
 *  - LEFT: customers who owe money, biggest balance first (search covers every
 *    customer by name/ΑΦΜ, still balance-ordered).
 *  - RIGHT: the chosen customer's balance + on-account credit, the amount, and
 *    their OPEN invoices (PaymentAllocator::openInvoiceRows — the allocator's own
 *    target set) with the amount pre-split oldest-first (FIFO). The operator can
 *    untick an invoice or retype a line; whatever is left of the amount becomes
 *    on-account credit (shown before saving).
 *
 * The write is PaymentAllocator::allocateSelected (locks + caps each invoice at
 * its live balance); «Συμψηφισμός πίστωσης» reuses applyCredit. No money math of
 * its own here — the screen only proposes the split. Gated on the SAME permission
 * as the plain «Δημιουργία πληρωμής» (PaymentResource::canCreate).
 */
class ReceivePayment extends Page
{
    protected static string $resource = PaymentResource::class;

    protected string $view = 'filament.resources.payments.pages.receive-payment';

    #[Url(as: 'customer')]
    public ?int $customerId = null;

    public string $search = '';

    public string $amount = '';

    /**
     * Keyed by invoice id. `manual` = the operator typed this line's amount, so a
     * re-split (new amount / tick) keeps it and only fills the other ticked lines.
     *
     * @var array<int|string, array{on: bool, amount: string, manual?: bool}>
     */
    public array $lines = [];

    /**
     * The picker list + the selected customer's balance/credit, refreshed only when
     * the search, the selection or the books change (mount / search / save) — NOT on
     * every amount keystroke: withOutstandingBalance aggregates the whole tenant.
     *
     * @var list<array{id: int, name: string, afm: ?string, balance: float}>
     */
    public array $customerList = [];

    public bool $customerListMore = false;

    /** @var array{balance: float, credit: float} */
    public array $snapshot = ['balance' => 0.0, 'credit' => 0.0];

    private const LIST_LIMIT = 40;

    public ?string $paymentMethodId = null;

    public ?string $bankAccountId = null;

    public string $payDate = '';

    public string $transactionId = '';

    public string $notes = '';

    /** Per-request memo (not Livewire state). */
    private ?array $openRowsCache = null;

    public static function canAccess(array $parameters = []): bool
    {
        return Filament::getTenant() instanceof Company && PaymentResource::canCreate();
    }

    public function getTitle(): string
    {
        return 'Νέα είσπραξη';
    }

    public function getBreadcrumb(): string
    {
        return 'Νέα είσπραξη';
    }

    public function mount(): void
    {
        $this->payDate = now()->toDateString();
        if ($this->customerId !== null && $this->customer() === null) {
            $this->customerId = null; // not this tenant's / gone → ignore the URL param
        }
        if ($this->customerId !== null) {
            $this->resetForCustomer();
        }
        $this->refreshCustomerList();
    }

    public function updatedSearch(): void
    {
        $this->refreshCustomerList();
    }

    /* ===================== Customer picker ===================== */

    /**
     * Debtors first (biggest balance on top). With a search term every matching
     * customer is listed — a receipt can come from someone with a zero balance
     * (an advance) — still ordered by balance.
     *
     * @return list<array{id: int, name: string, afm: ?string, balance: float}>
     */
    public function customerRows(): array
    {
        return $this->customerList;
    }

    private function refreshCustomerList(): void
    {
        $tenantId = $this->tenantId();
        $q = Customer::query()
            ->where('customers.company_id', $tenantId)
            ->withOutstandingBalance($tenantId);

        $term = trim($this->search);
        if ($term !== '') {
            $q->where(fn ($w) => $w->where('customers.name', 'like', '%'.$term.'%')
                ->orWhere('customers.afm', 'like', '%'.$term.'%'));
        } else {
            $q->onlyDebtors();
        }

        $rows = $q->orderByDesc('outstanding_balance')
            ->orderBy('customers.name')
            ->limit(self::LIST_LIMIT + 1)
            ->get()
            ->map(fn (Customer $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'afm' => $c->afm,
                'balance' => round((float) $c->outstanding_balance, 2),
            ])
            ->all();

        $this->customerListMore = count($rows) > self::LIST_LIMIT;
        $this->customerList = array_slice($rows, 0, self::LIST_LIMIT);
    }

    public function selectCustomer(int $id): void
    {
        $this->customerId = $id;
        if ($this->customer() === null) {
            $this->customerId = null;

            return;
        }
        $this->resetForCustomer();
    }

    private function resetForCustomer(): void
    {
        $this->openRowsCache = null;
        $this->snapshot = $this->loadSnapshot();
        $this->transactionId = '';
        $this->notes = '';
        $this->lines = [];
        foreach ($this->openRows() as $id => $row) {
            $this->lines[$id] = ['on' => true, 'amount' => '', 'manual' => false];
        }
        $balance = $this->snapshot['balance'];
        $this->amount = $balance > 0.005 ? $this->fmtInput($balance) : '';
        $this->distribute();
    }

    /* ===================== Selected customer ===================== */

    public function customer(): ?Customer
    {
        if ($this->customerId === null) {
            return null;
        }

        return Customer::query()
            ->where('company_id', $this->tenantId())
            ->find($this->customerId);
    }

    /** @return array<int, array{id: int, invcode: string, issued_at: ?string, due_at: ?string, balance: float}> */
    public function openRows(): array
    {
        if ($this->openRowsCache !== null) {
            return $this->openRowsCache;
        }
        $customer = $this->customer();

        return $this->openRowsCache = $customer ? app(PaymentAllocator::class)->openInvoiceRows($customer) : [];
    }

    /** @return array{balance: float, credit: float} */
    public function summary(): array
    {
        return $this->snapshot;
    }

    /** @return array{balance: float, credit: float} */
    private function loadSnapshot(): array
    {
        $customer = $this->customer();
        if ($customer === null) {
            return ['balance' => 0.0, 'credit' => 0.0];
        }
        $tenantId = $this->tenantId();
        $balance = (float) (Customer::query()
            ->where('customers.company_id', $tenantId)
            ->withOutstandingBalance($tenantId)
            ->where('customers.id', $customer->id)
            ->value('outstanding_balance') ?? 0);

        return [
            'balance' => round($balance, 2),
            'credit' => app(PaymentAllocator::class)->availableCredit($customer),
        ];
    }

    public function ledgerUrl(): ?string
    {
        $customer = $this->customer();

        return $customer && CustomerResource::canView($customer)
            ? CustomerResource::getUrl('ledger', ['record' => $customer])
            : null;
    }

    /* ===================== Split (UI proposal only) ===================== */

    /**
     * Split the amount oldest-first over the TICKED invoices. Lines the operator
     * typed (`manual`) are kept as-is and come off the top; only the other ticked
     * lines are (re)filled from what is left.
     */
    public function distribute(): void
    {
        $rows = $this->openRows();
        $left = $this->parseAmount($this->amount) ?? 0.0;
        foreach ($rows as $id => $row) {
            if (($this->lines[$id]['on'] ?? false) && ($this->lines[$id]['manual'] ?? false)) {
                $left = round($left - ($this->parseAmount($this->lines[$id]['amount'] ?? '') ?? 0.0), 2);
            }
        }
        foreach ($rows as $id => $row) {
            if (! ($this->lines[$id]['on'] ?? false)) {
                $this->lines[$id] = ['on' => false, 'amount' => '', 'manual' => false];

                continue;
            }
            if ($this->lines[$id]['manual'] ?? false) {
                continue;
            }
            $pay = round(min($row['balance'], max(0, $left)), 2);
            $this->lines[$id] = ['on' => true, 'amount' => $pay > 0.005 ? $this->fmtInput($pay) : '', 'manual' => false];
            $left = round($left - $pay, 2);
        }
    }

    /** «Ξανά-κατανομή (FIFO)»: drop the operator's line overrides and re-split from scratch. */
    public function redistribute(): void
    {
        foreach (array_keys($this->lines) as $id) {
            $this->lines[$id]['manual'] = false;
        }
        $this->distribute();
    }

    public function updatedAmount(): void
    {
        $this->distribute();
    }

    public function updatedLines(mixed $value, string $key): void
    {
        // Typing a line amount = the operator overriding the split → pin it.
        // Ticking/unticking re-splits the rest.
        if (str_ends_with($key, '.amount')) {
            $id = substr($key, 0, -strlen('.amount'));
            if (isset($this->lines[$id])) {
                $this->lines[$id]['manual'] = true;
            }
        } elseif (str_ends_with($key, '.on')) {
            $this->distribute();
        }
    }

    public function payAll(): void
    {
        $total = array_sum(array_column($this->openRows(), 'balance'));
        foreach (array_keys($this->openRows()) as $id) {
            $this->lines[$id]['on'] = true;
            $this->lines[$id]['manual'] = false;
        }
        $this->amount = $total > 0.005 ? $this->fmtInput($total) : $this->amount;
        $this->distribute();
    }

    public function payOnly(int $invoiceId): void
    {
        $rows = $this->openRows();
        if (! isset($rows[$invoiceId])) {
            return;
        }
        foreach (array_keys($rows) as $id) {
            $this->lines[$id]['on'] = $id === $invoiceId;
            $this->lines[$id]['manual'] = false;
        }
        $this->amount = $this->fmtInput($rows[$invoiceId]['balance']);
        $this->distribute();
    }

    /**
     * Live totals for the footer: what goes to invoices, what is left as
     * on-account credit, and the balance after saving.
     *
     * @return array{amount: float, toInvoices: float, onAccount: float, newBalance: float, over: list<string>}
     */
    public function totals(): array
    {
        $amount = $this->parseAmount($this->amount) ?? 0.0;
        $toInvoices = 0.0;
        $over = [];
        foreach ($this->openRows() as $id => $row) {
            if (! ($this->lines[$id]['on'] ?? false)) {
                continue;
            }
            $line = $this->parseAmount($this->lines[$id]['amount'] ?? '') ?? 0.0;
            if ($line > $row['balance'] + 0.005) {
                $over[] = $row['invcode'];
            }
            $toInvoices = round($toInvoices + $line, 2);
        }

        return [
            'amount' => $amount,
            'toInvoices' => $toInvoices,
            'onAccount' => round(max(0, $amount - $toInvoices), 2),
            'newBalance' => round($this->summary()['balance'] - $amount, 2),
            'over' => $over,
        ];
    }

    /* ===================== Writes ===================== */

    public function save(): void
    {
        $customer = $this->customer();
        if ($customer === null) {
            $this->notifyError('Διάλεξε πελάτη.');

            return;
        }

        $amount = $this->parseAmount($this->amount);
        if ($amount === null || $amount <= 0) {
            $this->notifyError('Δώσε θετικό ποσό είσπραξης.');

            return;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $this->payDate)->startOfDay();
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date) {
            $this->notifyError('Μη έγκυρη ημερομηνία.');

            return;
        }

        $methodId = filled($this->paymentMethodId) ? (int) $this->paymentMethodId : null;
        if ($methodId !== null && ! PaymentMethod::query()->where('company_id', $customer->company_id)->whereKey($methodId)->exists()) {
            $this->notifyError('Μη έγκυρος τρόπος πληρωμής.');

            return;
        }
        $bankId = filled($this->bankAccountId) ? (int) $this->bankAccountId : null;
        if ($bankId !== null && ! BankAccount::belongsToTenant($bankId, $customer->company_id)) {
            $this->notifyError('Μη έγκυρος τραπεζικός λογαριασμός.');

            return;
        }

        $rows = $this->openRows();
        $lines = [];
        foreach ($rows as $id => $row) {
            if (! ($this->lines[$id]['on'] ?? false)) {
                continue;
            }
            $line = $this->parseAmount($this->lines[$id]['amount'] ?? '');
            if ($line === null || $line <= 0) {
                continue;
            }
            if ($line > $row['balance'] + 0.005) {
                $this->notifyError('Το ποσό στο '.$row['invcode'].' ξεπερνά την οφειλή του ('.Money::eur($row['balance']).').');

                return;
            }
            $lines[] = ['invoice_id' => $id, 'amount' => $line];
        }

        try {
            $res = app(PaymentAllocator::class)->allocateSelected(
                $customer,
                $amount,
                $lines,
                $date,
                $methodId,
                null,
                filled($this->notes) ? trim($this->notes) : null,
                filled($this->transactionId) ? mb_substr(trim($this->transactionId), 0, 100) : null,
                $bankId,
            );
        } catch (InvalidArgumentException $e) {
            $this->notifyError($e->getMessage());

            return;
        }

        $body = count($res->allocations).' παραστατικά ('.Money::eur($res->allocatedToInvoices()).')';
        if ($res->onAccount > 0.005) {
            $body .= ' + '.Money::eur($res->onAccount).' έναντι λογαριασμού';
        }
        Notification::make()->success()
            ->title('Η είσπραξη καταχωρίστηκε · '.$res->reference)
            ->body($body)
            ->send();

        $this->resetForCustomer();
        $this->refreshCustomerList();
    }

    /**
     * Apply the customer's existing on-account credit to their open invoices,
     * oldest first (PaymentAllocator::applyCredit per invoice — re-points the
     * credit rows, net-zero to the total balance). All-or-nothing.
     */
    public function applyCreditFifo(): void
    {
        $customer = $this->customer();
        if ($customer === null) {
            return;
        }
        $allocator = app(PaymentAllocator::class);

        try {
            $applied = DB::transaction(function () use ($customer, $allocator): array {
                $applied = [];
                $credit = $allocator->availableCredit($customer);
                foreach ($allocator->openInvoiceRows($customer) as $row) {
                    if ($credit <= 0.005) {
                        break;
                    }
                    $invoice = $customer->invoices()->whereKey($row['id'])->first();
                    if ($invoice === null) {
                        continue;
                    }
                    $done = $allocator->applyCredit($customer, $invoice, min($credit, $row['balance']));
                    if ($done > 0.005) {
                        $applied[] = $row['invcode'].' '.Money::eur($done);
                        $credit = round($credit - $done, 2);
                    }
                }

                return $applied;
            });
        } catch (InvalidArgumentException $e) {
            $this->notifyError($e->getMessage());

            return;
        }

        if ($applied === []) {
            Notification::make()->warning()->title('Δεν εφαρμόστηκε πίστωση')->send();
        } else {
            Notification::make()->success()->title('Η πίστωση εφαρμόστηκε')->body(implode(' · ', $applied))->send();
        }
        $this->resetForCustomer();
        $this->refreshCustomerList();
    }

    /* ===================== View helpers ===================== */

    /** @return array<int|string, string> */
    public function paymentMethodOptions(): array
    {
        return PaymentMethod::query()
            ->where('company_id', $this->tenantId())
            ->pluck('description', 'id')
            ->all();
    }

    /** @return array<int|string, string> */
    public function bankAccountOptions(): array
    {
        return BankAccount::activeOptions($this->tenantId());
    }

    public function money(float $value): string
    {
        return Money::eur($value);
    }

    /** Days past the due date (Invoice::dueDate — same basis as the overdue badges); null when not overdue / cash-term. */
    public function overdueDays(?string $dueAt): ?int
    {
        if (! $dueAt) {
            return null;
        }
        $days = (int) Carbon::parse($dueAt)->startOfDay()->diffInDays(now()->startOfDay(), false);

        return $days > 0 ? $days : null;
    }

    /**
     * Accepts «1.234,56», «1234,56», «1234.56» and the Greek thousands-only «1.234»
     * (= 1234, NOT 1,234 — a dot followed by exactly three digits, no comma).
     * Null when not a number.
     */
    public function parseAmount(?string $raw): ?float
    {
        $s = str_replace([' ', '€', "\u{00A0}"], '', trim((string) $raw));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        if (! is_numeric($s)) {
            return null;
        }

        return round((float) $s, 2);
    }

    private function fmtInput(float $value): string
    {
        return number_format($value, 2, ',', '');
    }

    private function notifyError(string $message): void
    {
        Notification::make()->danger()->title('Δεν καταχωρίστηκε')->body($message)->send();
    }

    private function tenantId(): int
    {
        return (int) Filament::getTenant()?->getKey();
    }
}
