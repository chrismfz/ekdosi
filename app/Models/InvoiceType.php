<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

class InvoiceType extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'invcount',
        'show_on_menu',
        'is_favorite',
        'is_credit',
        'is_return',
        // Combined ΤΔΑ (Slice 3a): a «ΤΔΑ» type pre-sets invoices.is_delivery_note.
        'is_delivery_note',
        // Άτυπη (μη φορολογική) σειρά — never myDATA, never in any total.
        'is_informal',
        'mydata_type',
        'mydata_income_class',
        'mydata_income_class_category',
        'mydata_requires_quantity',
        'distribution_aim_id',
        'delivery_method_id',
        'payment_method_id',
        'default_customer_id',
    ];

    protected function casts(): array
    {
        return [
            'invcount' => 'integer',
            'show_on_menu' => 'boolean',
            'is_favorite' => 'boolean',
            'is_credit' => 'boolean',
            'is_return' => 'boolean',
            'is_delivery_note' => 'boolean',
            'is_informal' => 'boolean',
            'mydata_requires_quantity' => 'boolean',
        ];
    }

    /**
     * Tenant relation — required by Filament's BelongsToTenant trait
     * when InvoiceTypeResource lands.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function defaultCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'default_customer_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(DeliveryMethod::class);
    }

    public function distributionAim(): BelongsTo
    {
        return $this->belongsTo(DistributionAim::class);
    }

    protected static function booted(): void
    {
        // The informal flag decides whether a document is a tax document at all. Once
        // the series has ANY document it is frozen: flipping a fiscal series to
        // informal would retroactively drop real invoices from VAT/receivables (and a
        // «draft» can already be filed, numbered, or hold a customer's payment), and
        // the reverse would turn internal documents into unfiled «sales». Need the
        // other kind? Open a new series.
        static::saving(function (InvoiceType $type): void {
            if ($type->exists && $type->isDirty('is_informal') && $type->hasInvoices()) {
                throw new RuntimeException('Η σειρά '.$type->code.' έχει ήδη παραστατικά — δεν αλλάζει σε/από άτυπη. Φτιάξε νέα σειρά.');
            }
            if ($type->is_informal && (filled($type->mydata_type) || $type->is_credit || $type->is_delivery_note)) {
                throw new RuntimeException('Μια άτυπη σειρά δεν έχει myDATA τύπο και δεν είναι πιστωτικό ή δελτίο αποστολής.');
            }
        });
    }

    /** «ΕΣΩ — Εσωτερικά (άτυπη)»: the one label every series picker renders. */
    public function pickerLabel(): string
    {
        return $this->code.' — '.$this->name.($this->is_informal ? ' (άτυπη)' : '');
    }

    /** Any document of this series at all — drafts and trashed included. */
    public function hasInvoices(): bool
    {
        return Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->withTrashed()
            ->where('company_id', $this->company_id)
            ->where('invoice_type_id', $this->getKey())
            ->exists();
    }

    /**
     * Monetary invoice types only — EXCLUDES the movement-only 9.x Δελτία
     * Αποστολής, which are not money documents and belong to the Delivery
     * Notes flow (DeliveryNoteSubmitter), never an invoice/quote picker
     * (MYD-003). The ONE shared predicate for every monetary invoice-type
     * selector so the surfaces cannot drift; mirrors Codes::isMovementOnlyType
     * (prefix '9.'). Null-safe: a legacy type with no mydata_type stays
     * selectable (we can't prove it's movement-only, so we don't hide it).
     */
    public function scopeMonetary(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('mydata_type')
            ->orWhere('mydata_type', 'not like', '9.%'));
    }
}
