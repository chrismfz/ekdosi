<?php

namespace App\Support\Settings;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Read/write façade over the `system_settings` table — the deploy-wide typed
 * store behind the «Σύστημα» settings UI.
 *
 * Contract: a key that is ABSENT returns the caller's `$default` (always the
 * env/config value), so an empty table reproduces exactly today's behaviour and
 * env stays the source of the default. A present key OVERRIDES it.
 *
 * Resilience: every read is wrapped — a missing table (fresh DB, mid-migrate) or
 * any DB error falls back to the default, so `routes/console.php` evaluating a
 * `->when()` filter can never crash the scheduler. The whole table is loaded once
 * and cached (busted on write); reads are a single cache hit.
 */
class SystemSettings
{
    private const CACHE_KEY = 'system_settings.map';

    /** @var array<string, array{value: ?string, type: string}>|null */
    private ?array $map = null;

    public function bool(string $key, bool $default = false): bool
    {
        $raw = $this->raw($key);

        return $raw === null ? $default : filter_var($raw['value'], FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default = 0): int
    {
        $raw = $this->raw($key);

        return $raw === null ? $default : (int) $raw['value'];
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $raw = $this->raw($key);

        return $raw === null ? $default : $raw['value'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->raw($key);
        if ($raw === null) {
            return $default;
        }

        return match ($raw['type']) {
            'bool' => filter_var($raw['value'], FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $raw['value'],
            'json' => json_decode((string) $raw['value'], true),
            default => $raw['value'],
        };
    }

    /** True when an explicit row exists for $key (i.e. an operator override is in effect). */
    public function has(string $key): bool
    {
        return $this->raw($key) !== null;
    }

    public function set(string $key, mixed $value, string $type = 'string', ?int $userId = null): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $this->serialize($value, $type), 'type' => $type, 'updated_by' => $userId],
        );

        $this->flush();
    }

    public function setBool(string $key, bool $value, ?int $userId = null): void
    {
        $this->set($key, $value, 'bool', $userId);
    }

    public function forget(string $key): void
    {
        SystemSetting::query()->where('key', $key)->delete();
        $this->flush();
    }

    /** Drop the in-process + cache copies so the next read reloads from the DB. */
    public function flush(): void
    {
        $this->map = null;
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // ignore
        }
    }

    private function serialize(mixed $value, string $type): ?string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => $value === null ? null : (string) $value,
        };
    }

    /**
     * @return array{value: ?string, type: string}|null
     */
    private function raw(string $key): ?array
    {
        return $this->load()[$key] ?? null;
    }

    /**
     * @return array<string, array{value: ?string, type: string}>
     */
    private function load(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        try {
            $this->map = Cache::rememberForever(self::CACHE_KEY, fn () => SystemSetting::query()
                ->get(['key', 'value', 'type'])
                ->mapWithKeys(fn (SystemSetting $s) => [$s->key => ['value' => $s->value, 'type' => $s->type]])
                ->all());
        } catch (Throwable) {
            // Table missing / DB down → behave as an empty store (callers fall back to defaults).
            $this->map = [];
        }

        return $this->map;
    }
}
