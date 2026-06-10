<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One deploy-wide setting row. Written/read only through
 * {@see \App\Support\Settings\SystemSettings} (which caches + casts) — models
 * elsewhere should not touch this table directly.
 *
 * @property string $key
 * @property string|null $value
 * @property string $type   bool|int|string|json
 * @property int|null $updated_by
 */
class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'updated_by'];
}
