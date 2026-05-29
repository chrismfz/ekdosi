<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One myDATA call on the expense side — the legal audit trail twin of
 * MyDataMark (RequestDocs pulls, SendExpensesClassification posts,
 * cancellations).
 *
 * The full request + response XML is preserved verbatim per row — DO NOT
 * TRUNCATE OR NORMALISE on read. `expenses.mydata_*` columns are a cache of
 * the latest state; this is the source of truth.
 *
 * As in MyDataMark, `mark_time` is a plain TIME string (left uncast) so
 * Carbon doesn't synthesise today's date and break time comparisons.
 */
class ExpenseMark extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'expense_id',
        'mark',
        'mydata_action',
        'request',
        'response',
        'mark_date',
        'mark_time',
    ];

    protected function casts(): array
    {
        return [
            'mark_date' => 'date',
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
