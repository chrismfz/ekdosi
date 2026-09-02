<?php

namespace Tests\Feature\Updates;

use Tests\TestCase;

/**
 * The deploy deadlock guard.
 *
 * `deploy/update.sh` step 10 runs `shield:generate --ignore-existing-policies`,
 * and the seeder does the same on every suite run. For a resource that ships
 * WITHOUT a committed policy, that WRITES `app/Policies/<Model>Policy.php` as an
 * untracked file — which used to make the next deploy's clean-tree pre-flight
 * refuse, unclearable with `git stash`. (It happened for real, with
 * `UpdateRunPolicy`, on a production box.)
 *
 * Two independent fixes, one test each: no resource may ship policy-less, and
 * the pre-flight must not refuse over untracked files anyway.
 */
class ShieldPolicyDriftTest extends TestCase
{
    public function test_every_filament_resource_model_has_a_committed_policy(): void
    {
        $missing = [];

        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
            if (! preg_match('/static \?string \$model = ([A-Za-z_]+)::class/', (string) file_get_contents($file), $m)) {
                continue;
            }

            if (! file_exists(app_path('Policies/'.$m[1].'Policy.php'))) {
                $missing[] = $m[1].' ('.basename($file).')';
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'These resources have no committed policy, so `shield:generate` writes one as an',
            'UNTRACKED file on every deploy and every suite run — which is what deadlocked the',
            'production deploy once. Commit a policy (hand-written when the stock CRUD-on-',
            'permissions template would be wrong, as for UpdateRun): '.implode(', ', $missing),
        ]));
    }

    public function test_the_deploy_preflight_does_not_refuse_over_untracked_files(): void
    {
        $script = (string) file_get_contents(base_path('deploy/update.sh'));

        $this->assertStringContainsString(
            'git status --porcelain --untracked-files=no',
            $script,
            'the clean-tree pre-flight must look at TRACKED changes only',
        );
        $this->assertStringNotContainsString(
            '$(git status --porcelain)"',
            $script,
            'a bare `git status --porcelain` counts untracked files and can deadlock the deploy',
        );
    }
}
