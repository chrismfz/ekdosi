<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item moving on a Δελτίο Αποστολής — quantity + description + measurement
 * unit, no price/VAT (value-less twin of InvoiceLine).
 */
class DeliveryNoteLine extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'delivery_note_id',
        'product_id',
        'qty',
        'measurement_unit',
        'metric_unit',
        'move_purpose_line',
        'product_descr',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'measurement_unit' => 'integer',
            'move_purpose_line' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            // Auto-stamp company_id from the parent note. Filament's
            // Repeater::relationship('lines') calls HasMany::create with only
            // the form fields (no company_id) — without this hook every form
            // save hits the NOT NULL constraint. Mirrors InvoiceLine::saving;
            // only fills when empty.
            if (empty($line->company_id) && $line->delivery_note_id) {
                $line->company_id = $line->deliveryNote?->company_id
                    ?? DeliveryNote::query()->whereKey($line->delivery_note_id)->value('company_id');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
