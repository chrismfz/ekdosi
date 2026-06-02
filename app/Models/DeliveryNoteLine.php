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
