<?php

namespace Tests\Feature\Updates;

use App\Models\UpdateRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The deploy/rollback pre-flight guards: they run BEFORE maintenance (so an abort
 * changes nothing) and never let a forced checkout destroy an operator's file
 * unseen — on every path: deploy/update.sh, deploy/rollback.sh and the in-app
 * «Επαναφορά» (SelfUpdate::runRollback).
 *
 * Everything runs FOR REAL against a throwaway git repo. The shell scripts get a
 * stub `php` that logs its calls and fails `artisan down`; the in-app rollback has
 * base_path() pointed at the repo, which has no `artisan`, so its `down` fails the
 * same way. Either way each run stops right after the pre-flight, and the test
 * sees exactly what it did (or did not) touch. BOTH scripts go through the same
 * scenarios, so a fix applied to one copy of the logic but not the other fails.
 *
 * Fixture — v1 is the older ref (the rollback / downgrade target), v2 = HEAD:
 *   a.txt     v1 tracks it, v2 deletes it          → operator's own untracked a.txt
 *   ign.txt   v1 tracks it, v2 untracks + ignores  → operator's own GITIGNORED ign.txt
 *   foo       v1 tracks a FILE foo, v2 deletes it  → operator's untracked DIR foo/bar
 *   dir/x     v1 tracks dir/x, v2 deletes it       → operator's untracked FILE dir
 *   b.txt, public/.htaccess — tracked in both.
 */
class DeployPreflightGuardsTest extends TestCase
{
    use RefreshDatabase;

    /** What `checkout --force v1` would destroy that git has no copy of. */
    private const OPERATOR_FILES = [
        'a.txt' => "MINE-A\n",
        'dir' => "MINE-DIR\n",
        'foo/bar' => "MINE-FOO\n",
        'ign.txt' => "MINE-IGN\n",
    ];

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

    /** @return array<string, array{string, array<string, string>}> */
    public static function scriptsTargetingV1(): array
    {
        return [
            'rollback.sh' => ['rollback.sh', []],
            'update.sh (deliberate downgrade)' => ['update.sh', ['ALLOW_DOWNGRADE' => '1']],
        ];
    }

    // ───────────────────────────── both scripts ─────────────────────────────

    /** @param array<string, string> $env */
    #[DataProvider('scriptsTargetingV1')]
    public function test_it_copies_aside_everything_the_checkout_would_destroy_before_maintenance(string $script, array $env): void
    {
        $this->fixture();

        [$code, $out] = $this->runScript($script, 'v1', $env);

        // The stub `php` fails `artisan down` and the script exits right there —
        // so the copies it left were taken BEFORE maintenance, and nothing was
        // checked out.
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('artisan down', (string) $this->phpCalls(), $out);
        $this->assertBackedUp(self::OPERATOR_FILES, $out);
        $this->assertOperatorFilesIntact();
    }

    /** @param array<string, string> $env */
    #[DataProvider('scriptsTargetingV1')]
    public function test_it_refuses_a_checkout_that_would_die_on_an_edited_skip_worktree_file(string $script, array $env): void
    {
        // v2 changed public/.htaccess and cPanel edited it on the server; the script
        // flags it skip-worktree itself. `checkout --force v1` would exit 128
        // («not uptodate») with the site already down — refuse before that.
        $this->fixture(releaseChangesHtaccess: true);
        File::append($this->repo.'/public/.htaccess', "# php -- BEGIN cPanel-generated handler\n");

        [$code, $out] = $this->runScript($script, 'v1', $env);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('changes public/.htaccess', $out);
        $this->assertStringContainsString('git update-index --no-skip-worktree public/.htaccess', $out);
        $this->assertStringNotContainsString('artisan down', (string) $this->phpCalls(), 'refused before maintenance');
        $this->assertSame([], $this->backups(), 'a refused run leaves no copies');
    }

    // ────────────────────────────── rollback.sh ─────────────────────────────

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
        // v2 = HEAD: nothing the checkout touches collides with the operator's
        // untracked/ignored leftovers — and they must not block it either (a bare
        // `git status --porcelain` would count them: the shield:generate deadlock).
        $this->fixture();

        [$code, $out] = $this->runScript('rollback.sh', 'v2');

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('not clean', $out);
        $this->assertStringContainsString('artisan down', (string) $this->phpCalls(), 'it went on to maintenance');
        $this->assertSame([], $this->backups(), 'nothing the checkout would destroy');
    }

    public function test_rollback_ignores_an_environment_managed_htaccess_edit_the_target_does_not_change(): void
    {
        // cPanel's MultiPHP rewrites public/.htaccess: that must not read as a
        // dirty tree (same skip-worktree as update.sh), or no rollback could run.
        $this->fixture();
        File::append($this->repo.'/public/.htaccess', "# php -- BEGIN cPanel-generated handler\n");

        [, $out] = $this->runScript('rollback.sh', 'v2');

        $this->assertStringNotContainsString('not clean', $out);
        $this->assertStringNotContainsString('skip-worktree) —', $out);
        $this->assertStringContainsString('artisan down', (string) $this->phpCalls());
    }

    // ─────────────────────────────── update.sh ──────────────────────────────

    public function test_an_update_refused_by_a_later_check_leaves_no_copies(): void
    {
        // v1 is an ancestor of HEAD → the downgrade guard refuses. The operator's
        // files WOULD be copied — but only once every refuse-check has passed.
        $this->fixture();

        [$code, $out] = $this->runScript('update.sh', 'v1');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('refusing to downgrade', $out);
        $this->assertSame([], $this->backups(), 'a refused deploy must not leave copies behind');
        $this->assertStringNotContainsString('Copies kept', $out);
    }

    // ─────────────────────── in-app «Επαναφορά» (php) ───────────────────────

    public function test_in_app_rollback_copies_aside_everything_the_checkout_would_destroy_before_maintenance(): void
    {
        $this->fixture();

        $run = $this->inAppRollback('v1');

        $this->assertSame(UpdateRun::STATUS_FAILED, $run->status, (string) $run->output);
        $this->assertSame('maintenance', $run->phase, 'it got past every guard and failed only at `artisan down`');
        $this->assertBackedUp(self::OPERATOR_FILES, (string) $run->output);
        $this->assertOperatorFilesIntact();
    }

    public function test_in_app_rollback_refuses_uncommitted_tracked_changes(): void
    {
        $this->fixture();
        File::append($this->repo.'/b.txt', "hand hotfix\n");

        $run = $this->inAppRollback('v1');

        $this->assertSame(UpdateRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('δεν είναι καθαρό', (string) $run->error_message);
        $this->assertStringContainsString('git stash', (string) $run->error_message);
        $this->assertNotSame('maintenance', $run->phase);
        $this->assertSame([], $this->backups());
    }

    public function test_in_app_rollback_refuses_an_unknown_ref(): void
    {
        $this->fixture();

        $run = $this->inAppRollback('no-such-ref');

        $this->assertSame(UpdateRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('Άγνωστο target ref', (string) $run->error_message);
        $this->assertNotSame('maintenance', $run->phase);
    }

    public function test_in_app_rollback_refuses_a_checkout_that_would_die_on_an_edited_skip_worktree_file(): void
    {
        // deploy/update.sh flagged public/.htaccess on this host earlier; cPanel
        // edited it since, and v1 has a different version.
        $this->fixture(releaseChangesHtaccess: true);
        File::append($this->repo.'/public/.htaccess', "# php -- BEGIN cPanel-generated handler\n");
        $this->git('update-index', '--skip-worktree', 'public/.htaccess');

        $run = $this->inAppRollback('v1');

        $this->assertSame(UpdateRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('public/.htaccess', (string) $run->error_message);
        $this->assertStringContainsString('skip-worktree', (string) $run->error_message);
        $this->assertSame('protect', $run->phase, 'refused before maintenance');
        $this->assertSame([], $this->backups());
    }

    // ─────────────────────────────── fixture ───────────────────────────────

    private function fixture(bool $releaseChangesHtaccess = false): void
    {
        foreach (['bash', 'git'] as $bin) {
            if ((new ExecutableFinder)->find($bin) === null) {
                $this->markTestSkipped("`{$bin}` is not available.");
            }
        }

        $this->repo = sys_get_temp_dir().'/ekdosi-deploy-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->repo.'/deploy');
        File::ensureDirectoryExists($this->repo.'/public');
        File::ensureDirectoryExists($this->repo.'/dir');
        File::copy(base_path('deploy/rollback.sh'), $this->repo.'/deploy/rollback.sh');
        File::copy(base_path('deploy/update.sh'), $this->repo.'/deploy/update.sh');
        File::put($this->repo.'/fakephp', "#!/usr/bin/env bash\necho \"\$*\" >> \"\$(pwd)/php.log\"\nexit 1\n");
        chmod($this->repo.'/fakephp', 0755);

        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'test');
        File::ensureDirectoryExists($this->repo.'/.git/info');
        File::append($this->repo.'/.git/info/exclude', "deploy/\nfakephp\nphp.log\nsnap.sql.gz\n");

        // v1 — the older ref.
        File::put($this->repo.'/a.txt', "v1 a\n");
        File::put($this->repo.'/ign.txt', "v1 ign\n");
        File::put($this->repo.'/foo', "v1 foo\n");
        File::put($this->repo.'/dir/x', "v1 x\n");
        File::put($this->repo.'/b.txt', "keep\n");
        File::put($this->repo.'/public/.htaccess', $releaseChangesHtaccess ? "# laravel v1\n" : "# laravel\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'v1');
        $this->git('tag', 'v1');

        // v2 — HEAD.
        $this->git('rm', '-q', 'a.txt', 'foo', 'dir/x');
        $this->git('rm', '-q', '--cached', 'ign.txt');
        File::put($this->repo.'/.gitignore', "ign.txt\n");
        if ($releaseChangesHtaccess) {
            File::put($this->repo.'/public/.htaccess', "# laravel v2\n");
        }
        $this->git('add', '.gitignore', 'public/.htaccess');
        $this->git('commit', '-q', '-m', 'v2');
        $this->git('tag', 'v2');

        // The operator's own files, standing where v1 would put its tracked ones.
        if (is_dir($this->repo.'/dir')) {
            rmdir($this->repo.'/dir');
        }
        File::ensureDirectoryExists($this->repo.'/foo');
        foreach (self::OPERATOR_FILES as $path => $content) {
            File::put($this->repo.'/'.$path, $content);
        }
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
     * @return array{int, string} exit code + output (ANSI stripped)
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

    /** Run a queued in-app rollback to $ref with base_path() pointed at the fixture repo. */
    private function inAppRollback(string $ref): UpdateRun
    {
        config(['ekdosi.updates.allow_in_app_apply' => true]);
        File::put($this->repo.'/snap.sql.gz', 'snapshot');

        $run = UpdateRun::create([
            'status' => UpdateRun::STATUS_QUEUED,
            'kind' => UpdateRun::KIND_ROLLBACK,
            'strategy' => UpdateRun::STRATEGY_PHP,
            'to_ref' => $ref,
            'restore_snapshot' => $this->repo.'/snap.sql.gz',
        ]);

        $this->app->setBasePath($this->repo);
        Artisan::call('ekdosi:self-update', ['--run' => $run->id]);

        return $run->fresh();
    }

    /** @param array<string, string> $expected path => content */
    private function assertBackedUp(array $expected, string $context): void
    {
        $got = [];
        foreach ($this->backups() as $relative) {
            [, $path] = explode('/', $relative, 2);   // drop the <timestamp>/ dir
            $got[$path] = File::get($this->repo.'/storage/app/deploy-untracked/'.$relative);
        }
        ksort($got);
        ksort($expected);

        $this->assertSame($expected, $got, "the operator's own bytes are what was kept\n".$context);
    }

    private function assertOperatorFilesIntact(): void
    {
        foreach (self::OPERATOR_FILES as $path => $content) {
            $this->assertSame($content, File::get($this->repo.'/'.$path), "{$path} was not checked out over");
        }
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
