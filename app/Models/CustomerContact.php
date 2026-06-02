<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Επαφή ενός πελάτη — a named person behind the customer (λογιστήριο,
 * τεχνικός, υπεύθυνος…), with their own phone/email. Net-new concept (the
 * legacy app only had the flat customer contact fields); purely operator-
 * maintained, so no legacy_id / ETL hook.
 */
class CustomerContact extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'name',
        'role',
        'email',
        'phone',
        'is_primary',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A customer has at most one primary contact: when one is marked
        // primary, demote the others. `withoutEvents` avoids recursing back
        // into this hook; `customer_id` already bounds it to the right
        // customer (and the company scope to the tenant).
        static::saved(function (self $contact): void {
            if (! $contact->is_primary) {
                return;
            }

            static::withoutEvents(function () use ($contact): void {
                static::where('customer_id', $contact->customer_id)
                    ->whereKeyNot($contact->getKey())
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            });
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
