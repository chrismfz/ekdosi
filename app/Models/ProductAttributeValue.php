<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\TransliterateGreek;
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

    /**
     * SKU suffix: the explicit code, else the value in the app's ELOT-style Latin
     * (TransliterateGreek, «Μαύρο» → MAVRO) upper-cased. Only a value with no
     * Latin form at all falls back to the row id — «0» is a real size, not empty.
     */
    public function skuCode(): string
    {
        $code = trim((string) $this->code);
        if ($code !== '') {
            return Str::upper($code);
        }

        $latin = Str::upper(Str::limit(Str::slug(TransliterateGreek::toLatin($this->value), ''), 8, ''));

        return $latin !== '' ? $latin : (string) $this->id;
    }
}
