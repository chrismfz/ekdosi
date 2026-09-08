<?php

namespace App\Console\Commands;

use App\Services\Updates\UpdateChecker;
use App\Support\BuildInfo;
use Illuminate\Console\Command;

/**
 * Show the deployed build identity (SemVer + build stamp + sha) and, with
 * --check, the READ-ONLY «is a newer release available?» result. Handy for
 * support («what are you running?») and a cron warn line
 * (`ekdosi:version --check --json`). Never applies anything.
 */
class VersionInfo extends Command
{
    protected $signature = 'ekdosi:version {--check : Also query GitHub for a newer release} {--fresh : Bypass the cache when --check} {--json : Machine-readable output}';

    protected $description = 'Show the deployed build identity (and optionally check for a newer release).';

    public function handle(BuildInfo $build, UpdateChecker $checker): int
    {
        $update = $this->option('check') ? $checker->check((bool) $this->option('fresh')) : null;

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'build' => $build->toArray(),
                'update' => $update,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info($build->label());
        $this->components->twoColumnDetail('Έκδοση (SemVer)', $build->version());
        $this->components->twoColumnDetail('Build', $build->buildStamp() ?? '—');
        $this->components->twoColumnDetail('Commit', $build->sha() ?? '—');
        $this->components->twoColumnDetail('Ref', $build->ref() ?? '—');

        if ($update === null) {
            return self::SUCCESS;
        }

        $this->newLine();
        if (! ($update['ok'] ?? false)) {
            $this->warn('Έλεγχος ενημερώσεων: '.($update['error'] ?? 'απέτυχε').'.');

            return self::SUCCESS;
        }

        if ($update['update_available'] ?? false) {
            $behind = $update['commits_behind'];
            $tail = is_int($behind) && $behind > 0 ? " ({$behind} commits πίσω)" : '';
            $this->warn('Διαθέσιμη νέα έκδοση: '.$update['latest_version'].$tail.'.');
            if ($update['url'] ?? null) {
                $this->line('  '.$update['url']);
            }
        } else {
            $this->info('Είσαι στην πιο πρόσφατη έκδοση ('.$update['latest_version'].').');
        }

        return self::SUCCESS;
    }
}
