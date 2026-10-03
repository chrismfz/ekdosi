<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One «Ταμείο ημέρας» (POS PR 2b): opened with a cash float, closed with a count.
 * Open = closed_at null (one per company at a time — TillSessions). Written only
 * through App\Services\Pos\TillSessions.
 */
class PosSession extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'opened_by',
        'opened_at',
        'opening_float',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'closing_report' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
