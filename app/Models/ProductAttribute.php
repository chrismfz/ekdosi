<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-tenant variant axis («Χρώμα», «Μέγεθος», «Νούμερο παπουτσιών»…). `kind`
 * tells the stock grid which axis is rows (color) and which is columns (size).
 */
class ProductAttribute extends Model
{
    use BelongsToCompany;
    use HasFactory;

    public const KIND_COLOR = 'color';

    public const KIND_SIZE = 'size';

    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_COLOR => 'Χρώμα',
        self::KIND_SIZE => 'Μέγεθος / νούμερο',
        self::KIND_OTHER => 'Άλλο',
    ];

    protected $fillable = ['company_id', 'name', 'kind', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)->orderBy('sort')->orderBy('id');
    }
}
