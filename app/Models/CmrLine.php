<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One goods line of a CMR (boxes 6–12). Value-less — quantity/weight/volume +
 * Latin description, no price/VAT.
 */
class CmrLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'cmr_note_id',
        'marks_numbers', 'packages_count', 'packing_method', 'nature_en',
        'statistical_no', 'weight_kg', 'volume_m3', 'adr_class',
    ];

    protected function casts(): array
    {
        return [
            'packages_count' => 'integer',
            'weight_kg' => 'decimal:3',
            'volume_m3' => 'decimal:3',
        ];
    }

    public function cmrNote(): BelongsTo
    {
        return $this->belongsTo(CmrNote::class);
    }
}
