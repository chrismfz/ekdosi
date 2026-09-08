<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One registrar WRITE command's audit row (Πυλώνας A / A3) — request/response/
 * outcome per state-changing call (renew/register/transfer/…), README §9 «API
 * history». `status` = ok | adopted (the §6.6 already-renewed guard fired —
 * no registrar call was made) | failed. `request`/`response` are SANITIZED
 * snapshots — credentials never land here.
 */
class DomainRegistrarLog extends Model
{
    use BelongsToCompany;
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_ADOPTED = 'adopted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'domain_id',
        'registrar_connection_id',
        'action',
        'status',
        'request',
        'response',
        'error',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DomainRegistrarConnection::class, 'registrar_connection_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
