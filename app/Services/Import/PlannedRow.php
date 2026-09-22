<?php

namespace App\Services\Import;

/**
 * What one CSV row will do — decided by the dry run, executed by the import.
 *
 *   create     → a new record with $values
 *   fill       → an existing record ($existingId) gets $values, which hold ONLY
 *                its blank fields (never overwrites what is already there)
 *   unchanged  → matched an existing record that has nothing blank to fill
 *   error      → skipped; $errors say why
 */
final class PlannedRow
{
    public const CREATE = 'create';

    public const FILL = 'fill';

    public const UNCHANGED = 'unchanged';

    public const ERROR = 'error';

    public string $action = self::ERROR;

    public string $label = '';

    /** @var array<string, mixed> */
    public array $values = [];

    public ?int $existingId = null;

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, mixed> lookups created at import time (e.g. a new category name) */
    public array $pending = [];

    public function __construct(public readonly int $line) {}

    public function fail(string $message): self
    {
        $this->errors[] = $message;
        $this->action = self::ERROR;

        return $this;
    }

    public function warn(string $message): self
    {
        $this->warnings[] = $message;

        return $this;
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }
}
