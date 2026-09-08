<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant's bank account (τραπεζικός λογαριασμός) — a simple lookup, the twin
 * of PaymentMethod. Tags a payment with where the money landed and supplies the
 * deposit account printed on a transfer-paid invoice. No money logic.
 */
class BankAccount extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'bank_name',
        'iban',
        'account_name',
        'swift',
        'is_active',
        'show_on_invoices',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_on_invoices' => 'boolean',
        ];
    }

    /**
     * The tenant's accounts to PRINT on an invoice PDF — active + flagged for
     * invoices, ordered by bank name. Explicit company_id (safe under the no-op
     * global scope on the CLI/queue render path).
     *
     * @return Collection<int, BankAccount>
     */
    public static function invoiceAccounts(?int $companyId): Collection
    {
        if ($companyId === null) {
            return new Collection;
        }

        return static::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('show_on_invoices', true)
            ->orderBy('bank_name')
            ->get();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** A one-line label for pickers / PDF: «Τράπεζα — IBAN». */
    public function label(): string
    {
        return trim(implode(' — ', array_filter([
            $this->bank_name,
            $this->iban,
        ]))) ?: ($this->account_name ?? ('#'.$this->getKey()));
    }

    /**
     * Does account $id belong to tenant $companyId? The server-side guard
     * behind App\Filament\Support\BankAccountField — the dropdown only lists a
     * tenant's accounts, but a crafted request could submit a foreign id, and
     * bank_account_id is printed on the invoice PDF. (Includes soft-deleted/
     * inactive rows: same-tenant is the only thing that matters here.)
     */
    public static function belongsToTenant(int|string $id, ?int $companyId): bool
    {
        if ($companyId === null) {
            return false;
        }

        return static::query()
            ->where('company_id', $companyId)
            ->whereKey($id)
            ->exists();
    }

    /**
     * Active accounts for a tenant as id => label, for Filament Selects.
     *
     * @return array<int, string>
     */
    public static function activeOptions(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        return static::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('bank_name')
            ->get()
            ->mapWithKeys(fn (self $a) => [$a->getKey() => $a->label()])
            ->all();
    }
}
