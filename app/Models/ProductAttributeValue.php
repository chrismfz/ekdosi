<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One value of a variant axis («Μαύρο», «M», «42»), ordered by `sort`. */
class ProductAttributeValue extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = ['company_id', 'product_attribute_id', 'value', 'code', 'color_hex', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }

    /** SKU suffix: the explicit code, else the value transliterated to ASCII upper-case. */
    public function skuCode(): string
    {
        $code = trim((string) $this->code);
        if ($code !== '') {
            return Str::upper($code);
        }

        return Str::upper(Str::limit(Str::slug(Str::ascii($this->value), ''), 8, '')) ?: (string) $this->id;
    }
}
