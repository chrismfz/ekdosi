<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Γραμμή εξόδου — the twin of InvoiceLine, one row per <invoiceDetails> line
 * of a supplier doc.
 *
 * `vat_category` (§8.2) and `vat_exemption_category` (§8.3) are stored exactly
 * as AADE sent them — the expense side accepts 0%/exempt shapes the sales-side
 * rules would reject, so we never re-derive them here.
 */
class ExpenseLine extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'company_id',
        'expense_id',
        'line_number',
        'item_code',
        'item_descr',
        'quantity',
        'measurement_unit',
        'net_value',
        'vat_category',
        'vat_exemption_category',
        'vat_amount',
        'classification_type',
        'classification_category',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'net_value' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'vat_category' => 'integer',
            'vat_exemption_category' => 'integer',
            'line_number' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
