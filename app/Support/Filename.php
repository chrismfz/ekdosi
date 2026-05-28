<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Filesystem-safe slug for download / attachment filenames built from
 * user-entered names (customers, etc.). Transliterates Greek to ASCII
 * then strips anything outside [A-Za-z0-9_-], falling back when the
 * result is empty (e.g. a name that is entirely punctuation).
 */
class Filename
{
    public static function slug(?string $name, string $fallback = 'file'): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', Str::ascii((string) $name)) ?: $fallback;
    }
}
