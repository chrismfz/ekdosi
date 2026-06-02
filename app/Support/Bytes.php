<?php

namespace App\Support;

/**
 * Byte-size humanizer — the single home for "1234567 → 1.2 MB". Shared by the
 * Firebird/Epsilon import tables and file attachments so the format can't drift.
 */
class Bytes
{
    public static function forHumans(?int $bytes, string $forNull = '—'): string
    {
        if ($bytes === null) {
            return $forNull;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
