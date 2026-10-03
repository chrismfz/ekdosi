<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash put into (in) or taken out of (out) the till drawer during a session — not a sale. */
class PosCashMovement extends Model
{
    use BelongsToCompany;

    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = [
        'company_id',
        'pos_session_id',
        'user_id',
        'direction',
        'amount',
        'reason',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
