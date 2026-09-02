<?php

namespace Tests\Feature\Updates;

use Symfony\Component\Finder\Finder;
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
        $seen = 0;

        // Filament discovers resources RECURSIVELY, so walk the tree the same
        // way — a one-level glob would let a nested resource ship policy-less
        // with this test green.
        foreach (Finder::create()
            ->files()->in(app_path('Filament/Resources'))->name('*Resource.php') as $file) {
            // `Foo::class` or a fully-qualified `\App\Models\Foo::class` — take
            // the LAST segment either way (an FQN model used to be skipped).
            if (! preg_match('/static \?string \$model = ([\\\\A-Za-z0-9_]+)::class/', (string) $file->getContents(), $m)) {
                continue;
            }

            $seen++;
            $model = class_basename(trim($m[1], '\\'));

            if (! file_exists(app_path('Policies/'.$model.'Policy.php'))) {
                $missing[] = $model.' ('.$file->getFilename().')';
            }
        }

        // The regex is the weak link: if it stops matching, the loop above finds
        // nothing and reports a false green.
        $this->assertGreaterThanOrEqual(25, $seen, 'the model regex matched almost nothing — this guard is not actually looking at the resources');

        $this->assertSame([], $missing, implode("\n", [
            'These resources have no committed policy, so `shield:generate` writes one as an',
            'UNTRACKED file on every deploy and every suite run — which is what deadlocked the',
            'production deploy once. Commit a policy (hand-written when the stock CRUD-on-',
            'permissions template would be wrong, as for UpdateRun): '.implode(', ', $missing),
        ]));
    }

    public function test_neither_updater_refuses_over_untracked_files(): void
    {
        // BOTH entry points — the shell deploy and the in-app «php» updater. The
        // second one is the same defect by another route: it also runs
        // shield:generate, and its operator has no shell to clear the artefact.
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

        $selfUpdate = (string) file_get_contents(app_path('Console/Commands/SelfUpdate.php'));
        $this->assertStringContainsString("'--untracked-files=no'", $selfUpdate);
        $this->assertStringNotContainsString(
            "['git', 'status', '--porcelain']",
            $selfUpdate,
            'the in-app updater must not refuse over its own generated files either',
        );
    }
}
