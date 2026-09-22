<?php

namespace Tests\Feature\Updates;

use App\Console\Commands\SelfUpdate;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The deploy/rollback pre-flight guards: they must run BEFORE maintenance (so an
 * abort changes nothing) and never let a forced checkout destroy an operator's
 * file unseen — on the rollback path exactly as on the update path.
 *
 * The shell scripts run FOR REAL in a throwaway git repo, with a stub `php` that
 * logs its calls and fails `artisan down` — so every run stops right after the
 * pre-flight and the test sees exactly what it did (or did not) touch. A full
 * in-app apply mutates the repo, so SelfUpdate's rollback guards are pinned
 * structurally instead: their ORDER is the invariant.
 *
 * Fixture: v1 tracks a.txt; v2 deletes it; HEAD = v2; the operator's own
 * UNTRACKED a.txt ("MINE") sits where v1 would put its tracked one.
 */
class DeployPreflightGuardsTest extends TestCase
{
    /** Never let an ambient git env (e.g. a hook) redirect the fixture's git. */
    private const CLEAN_GIT_ENV = ['GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_INDEX_FILE' => false];

    private ?string $repo = null;

    protected function tearDown(): void
    {
        if ($this->repo !== null) {
            File::deleteDirectory($this->repo);
        }

        parent::tearDown();
    }

    public function test_rollback_copies_aside_an_untracked_file_the_target_tracks_before_maintenance(): void
    {
        $this->fixture();

        [$code, $out] = $this->runScript('rollback.sh', 'v1');

        $this->assertSame(1, $code, 'the stub `php` fails `artisan down`, so the run stops right there');
        $backups = $this->backups();
        $this->assertCount(1, $backups, $out);
        $this->assertStringEndsWith('/a.txt', $backups[0]);
        $this->assertSame("MINE\n", File::get($this->repo.'/storage/app/deploy-untracked/'.$backups[0]), "the operator's own bytes are what was kept");
        $this->assertSame("MINE\n", File::get($this->repo.'/a.txt'), 'nothing was checked out');
        $this->assertStringContainsString('copied aside: a.txt', $out);
        $this->assertStringContainsString('Maintenance mode ON', $out);
        $this->assertLessThan(strpos($out, 'Maintenance mode ON'), strpos($out, 'copied aside: a.txt'), 'the copy is taken BEFORE maintenance');
    }

    public function test_rollback_refuses_uncommitted_tracked_changes_before_touching_anything(): void
    {
        $this->fixture();
        File::append($this->repo.'/b.txt', "hand hotfix\n");

        [$code, $out] = $this->runScript('rollback.sh', 'v1');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Working tree not clean', $out);
        $this->assertNull($this->phpCalls(), 'refused before maintenance — `artisan` was never called');
        $this->assertSame([], $this->backups());
        $this->assertStringContainsString('hand hotfix', File::get($this->repo.'/b.txt'), 'the hotfix survives');
    }

    public function test_rollback_refuses_an_unknown_ref_before_touching_anything(): void
    {
        $this->fixture();

        [$code, $out] = $this->runScript('rollback.sh', 'no-such-ref');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown ref: no-such-ref', $out);
        $this->assertNull($this->phpCalls(), 'refused before maintenance — `artisan` was never called');
    }

    public function test_rollback_never_refuses_over_untracked_files_and_leaves_the_rest_alone(): void
    {
        // a.txt is untracked AND absent from v2: the deadlock case (a bare `git
        // status --porcelain` would count it and refuse) and nothing to protect.
        $this->fixture();

        [$code, $out] = $this->runScript('rollback.sh', 'v2');

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('not clean', $out);
        $this->assertStringContainsString('artisan down', (string) $this->phpCalls(), 'it went on to maintenance');
        $this->assertSame([], $this->backups(), 'nothing the checkout would replace');
    }

    public function test_rollback_ignores_an_environment_managed_htaccess_edit(): void
    {
        // cPanel's MultiPHP rewrites public/.htaccess: that must not read as a
        // dirty tree (same skip-worktree as update.sh), or no rollback could run.
        $this->fixture();
        File::append($this->repo.'/public/.htaccess', "# php -- BEGIN cPanel-generated handler\n");

        [, $out] = $this->runScript('rollback.sh', 'v2');

        $this->assertStringNotContainsString('not clean', $out);
        $this->assertStringContainsString('artisan down', (string) $this->phpCalls());
    }

    public function test_an_update_refused_by_a_later_check_leaves_no_untracked_copies(): void
    {
        // v1 is an ancestor of HEAD → the downgrade guard refuses. a.txt (untracked,
        // tracked in v1) WOULD be copied — but only once every refuse-check passed.
        $this->fixture();

        [$code, $out] = $this->runScript('update.sh', 'v1');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('refusing to downgrade', $out);
        $this->assertSame([], $this->backups(), 'a refused deploy must not leave copies behind');
        $this->assertStringNotContainsString('Copies kept', $out);
    }

    public function test_an_update_that_proceeds_still_copies_aside_before_maintenance(): void
    {
        // The other direction: moving the block must not lose the protection.
        $this->fixture();

        [$code, $out] = $this->runScript('update.sh', 'v1', ['ALLOW_DOWNGRADE' => '1']);

        $this->assertSame(1, $code, 'the stub `php` fails `artisan down`');
        $this->assertCount(1, $this->backups(), $out);
        $this->assertStringContainsString('Copies kept', $out);
        $this->assertStringContainsString('Maintenance mode ON', $out);
        $this->assertLessThan(strpos($out, 'Maintenance mode ON'), strpos($out, 'Copies kept'));
        $this->assertSame("MINE\n", File::get($this->repo.'/a.txt'), 'nothing was checked out');
    }

    public function test_the_in_app_rollback_runs_every_guard_before_maintenance(): void
    {
        $method = new ReflectionMethod(SelfUpdate::class, 'runRollback');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $last = -1;
        foreach ([
            "'rev-parse', '--verify'",   // resolve the target ref
            "'--untracked-files=no'",    // refuse a dirty TRACKED tree (never untracked)
            '$this->protectUntracked(',  // copy aside what the checkout replaces (the CALL, not a comment)
            "'Maintenance mode ON'",     // … only then go down
            "'checkout', '--force'",     // … and only then replace files
        ] as $step) {
            $pos = strpos($body, $step);
            $this->assertNotFalse($pos, "runRollback() lost its `{$step}` step");
            $this->assertGreaterThan($last, $pos, "`{$step}` is out of order in runRollback()");
            $last = $pos;
        }
    }

    // ─────────────────────────────── fixture ───────────────────────────────

    private function fixture(): void
    {
        foreach (['bash', 'git'] as $bin) {
            if ((new ExecutableFinder)->find($bin) === null) {
                $this->markTestSkipped("`{$bin}` is not available.");
            }
        }

        $this->repo = sys_get_temp_dir().'/ekdosi-deploy-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->repo.'/deploy');
        File::ensureDirectoryExists($this->repo.'/public');
        File::copy(base_path('deploy/rollback.sh'), $this->repo.'/deploy/rollback.sh');
        File::copy(base_path('deploy/update.sh'), $this->repo.'/deploy/update.sh');
        File::put($this->repo.'/fakephp', "#!/usr/bin/env bash\necho \"\$*\" >> \"\$(pwd)/php.log\"\nexit 1\n");
        chmod($this->repo.'/fakephp', 0755);

        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'test');
        File::ensureDirectoryExists($this->repo.'/.git/info');
        File::append($this->repo.'/.git/info/exclude', "deploy/\nfakephp\nphp.log\n");

        File::put($this->repo.'/a.txt', "v1 content\n");
        File::put($this->repo.'/b.txt', "keep\n");
        File::put($this->repo.'/public/.htaccess', "# laravel\n");
        $this->git('add', 'a.txt', 'b.txt', 'public/.htaccess');
        $this->git('commit', '-q', '-m', 'v1');
        $this->git('tag', 'v1');
        $this->git('rm', '-q', 'a.txt');
        $this->git('commit', '-q', '-m', 'v2');
        $this->git('tag', 'v2');

        // The operator's own file, where v1 would put its tracked a.txt.
        File::put($this->repo.'/a.txt', "MINE\n");
    }

    private function git(string ...$args): void
    {
        (new Process(
            ['git', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...$args],
            $this->repo,
            self::CLEAN_GIT_ENV,
            null,
            30,
        ))->mustRun();
    }

    /**
     * @param  array<string, string|false>  $env
     * @return array{int, string} exit code + output (ANSI stripped; stdout first, where the ordered progress lines are)
     */
    private function runScript(string $script, string $ref, array $env = []): array
    {
        $process = new Process(['bash', 'deploy/'.$script, $ref], $this->repo, array_merge([
            'PHP' => $this->repo.'/fakephp',
            'COMPOSER' => 'false',
            'ALLOW_DOWNGRADE' => '0',
            'QUEUE_STOP_CMD' => false,
            'QUEUE_START_CMD' => false,
        ], self::CLEAN_GIT_ENV, $env), null, 60);
        $process->run();

        $out = $process->getOutput().$process->getErrorOutput();

        return [(int) $process->getExitCode(), (string) preg_replace('/\e\[[0-9;]*m/', '', $out)];
    }

    /** @return list<string> backed-up files, relative to storage/app/deploy-untracked/ */
    private function backups(): array
    {
        $dir = $this->repo.'/storage/app/deploy-untracked';

        return is_dir($dir)
            ? array_values(array_map(fn ($f) => $f->getRelativePathname(), File::allFiles($dir)))
            : [];
    }

    private function phpCalls(): ?string
    {
        $log = $this->repo.'/php.log';

        return is_file($log) ? File::get($log) : null;
    }
}
