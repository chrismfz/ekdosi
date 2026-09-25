<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Accounting\E3YearTotals;
use Illuminate\Database\Eloquent\Model;

/**
 * AADE's Ε3 totals for one company-year (see the create_e3_year_snapshots
 * migration). Written only by {@see E3YearTotals::refresh()}.
 */
class E3YearSnapshot extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'year', 'income', 'expense', 'capex', 'doc_count', 'rows', 'through', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'income' => 'decimal:2',
            'expense' => 'decimal:2',
            'capex' => 'decimal:2',
            'doc_count' => 'integer',
            'rows' => 'array',
            'through' => 'date',
            'fetched_at' => 'datetime',
        ];
    }
}
