<?php

namespace App\Support\Install;

/**
 * One line in the installer's «Έλεγχος συστήματος» preflight (see
 * {@see RequirementsChecker}). A `required` requirement that is not `passed`
 * BLOCKS the install (the operator must fix it first); an optional one that
 * fails is only a warning that names what feature won't work.
 */
final class Requirement
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $passed,
        public readonly bool $required,
        public readonly string $detail = '',
        public readonly string $fix = '',
    ) {}

    /** A hard failure — required AND not met — that must stop the install. */
    public function blocks(): bool
    {
        return $this->required && ! $this->passed;
    }

    /** UI bucket: 'ok' (green), 'error' (red, blocking) or 'warn' (yellow, advisory). */
    public function severity(): string
    {
        if ($this->passed) {
            return 'ok';
        }

        return $this->required ? 'error' : 'warn';
    }
}
