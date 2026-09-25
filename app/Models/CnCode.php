<?php

namespace App\Models;

use App\Services\Taric\CnCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * One 8-digit Συνδυασμένη Ονοματολογία code for a given year (GLOBAL reference data —
 * no company_id). Written only by {@see CnCatalog}.
 */
class CnCode extends Model
{
    protected $fillable = ['year', 'code', 'description_el', 'path_el'];

    protected function casts(): array
    {
        return ['year' => 'integer'];
    }
}
