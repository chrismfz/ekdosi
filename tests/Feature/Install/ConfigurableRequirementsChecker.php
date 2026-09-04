<?php

namespace Tests\Feature\Install;

use App\Support\Install\RequirementsChecker;

/**
 * Test double for {@see RequirementsChecker}: every environment probe is a
 * tweakable property, so a test can simulate a missing extension / old PHP /
 * unwritable path WITHOUT touching the real runtime (which always has soap,
 * gd, … present on CI). Defaults = a fully healthy host (no blockers).
 */
class ConfigurableRequirementsChecker extends RequirementsChecker
{
    /** @var list<string> extensions to report as ABSENT */
    public array $absentExtensions = [];

    public string $php = '8.4.5';

    public bool $writable = true;

    public bool $envWritable = true;

    public bool $procOpen = true;

    public bool $secure = true;

    public string $iniValue = '1G';

    protected function phpVersion(): string
    {
        return $this->php;
    }

    protected function extensionLoaded(string $extension): bool
    {
        return ! in_array($extension, $this->absentExtensions, true);
    }

    protected function functionEnabled(string $function): bool
    {
        return $this->procOpen;
    }

    protected function pathWritable(string $path): bool
    {
        return $this->writable;
    }

    protected function envTargetWritable(): bool
    {
        return $this->envWritable;
    }

    protected function requestIsSecure(): bool
    {
        return $this->secure;
    }

    protected function iniRaw(string $key): string
    {
        return $this->iniValue;
    }
}
