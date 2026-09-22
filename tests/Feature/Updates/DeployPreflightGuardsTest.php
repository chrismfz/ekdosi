<?php

namespace Tests\Feature\Updates;

use App\Models\UpdateRun;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The deploy/rollback pre-flight guards: they run BEFORE maintenance (so an abort
 * changes nothing) and never let a forced checkout destroy an operator's file
 * unseen — on all four entry points: deploy/update.sh, deploy/rollback.sh, and
 * the in-app update + «Επαναφορά» (SelfUpdate::runPhp / runRollback).
 *
 * Everything runs FOR REAL against a throwaway git repo. The shell scripts get a
 * stub `php` that logs its calls and fails `artisan down`; the in-app paths have
 * base_path() pointed at the repo, which has no `artisan`, so their `down` fails
 * the same way. Either way each run stops right after the pre-flight, and the test
 * sees exactly what it did (or did not) touch. The same scenarios run through all
 * four, so a fix applied to one copy of the logic but not another fails.
 *
 * Fixture — v1 is the older ref (the rollback / downgrade target), v2 = HEAD:
 *   a.txt     v1 tracks it, v2 deletes it          → operator's own untracked a.txt
 *   ign.txt   v1 tracks it, v2 untracks + ignores  → operator's own GITIGNORED ign.txt
 *   foo       v1 tracks a FILE foo, v2 deletes it  → operator's untracked DIR foo/bar
 *   dir/x     v1 tracks dir/x, v2 deletes it       → operator's untracked FILE dir
 *   l/x, l/y  v1 tracks them, v2 deletes l/        → l is a SYMLINK to shared/ (with y)
 *   c.txt     differs between v1 and v2 (for the assume-unchanged case)
 *   b.txt, public/.htaccess — tracked in both.
 */
class DeployPreflightGuardsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What `checkout --force v1` would destroy that git has no copy of: the
     * operator's own bytes, plus the symlink itself (NOT shared/y behind it —
     * git replaces the link and never looks past it).
     */
    private const AT_RISK = [
        'a.txt' => "MINE-A\n",
        'dir' => "MINE-DIR\n",
        'foo/bar' => "MINE-FOO\n",
        'ign.txt' => "MINE-IGN\n",
        'l' => '-> shared',
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

    /** @return array<string, array{string}> */
    public static function entryPoints(): array
    {
        return [
            'rollback.sh' => ['rollback.sh'],
            'update.sh (deliberate downgrade)' => ['update.sh'],
            'in-app rollback' => ['in-app rollback'],
            'in-app update' => ['in-app update'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function scripts(): array
    {
        return ['rollback.sh' => ['rollback.sh'], 'update.sh (deliberate downgrade)' => ['update.sh']];
    }

    // ─────────────────────────── all four entry points ───────────────────────────

    #[DataProvider('entryPoints')]
    public function test_it_copies_aside_everything_the_checkout_would_destroy_before_maintenance(string $via): void
    {
        $this->fixture();

        [$reachedMaintenance, $out] = $this->attempt($via, 'v1');

        // Each run stops at `artisan down` — so the copies it left were taken
        // BEFORE maintenance, and nothing was checked out.
        $this->assertTrue($reachedMaintenance, $out);
        $this->assertSame(self::AT_RISK, $this->backedUp(), $out);
        $this->assertOperatorFilesIntact();
        $this->assertSame("SHARED-Y\n", File::get($this->repo.'/shared/y'), 'the symlinked dir was never read through or touched');
    }

    #[DataProvider('entryPoints')]
    public function test_it_refuses_a_checkout_that_would_die_on_an_edited_skip_worktree_file(string $via): void
    {
        // v2 changed public/.htaccess, it is flagged skip-worktree (update.sh does
        // that), and cPanel edited it since. `checkout --force v1` would exit 128
        // («not uptodate») with the site already down — refuse before that.
        $this->fixture(releaseChangesHtaccess: true);
        File::append($this->repo.'/public/.htaccess', "# php -- BEGIN cPanel-generated handler\n");
        $this->git('update-index', '--skip-worktree', 'public/.htaccess');

        [$reachedMaintenance, $out] = $this->attempt($via, 'v1');

        $this->assertFalse($reachedMaintenance, 'refused before maintenance');
        $this->assertStringContainsString('public/.htaccess', $out);
        $this->assertStringContainsString("--no-skip-worktree --no-assume-unchanged 'public/.htaccess'", $out);
        $this->assertSame([], $this->backedUp(), 'a refused run leaves no copies');
    }

    #[DataProvider('entryPoints')]
    public function test_it_refuses_a_flagged_file_with_the_same_bytes_but_a_new_timestamp(string $via): void
    {
        // Git decides «untouched» by STAT, not content: cPanel re-saving identical
        // bytes (or a touch, or a copy restored over it) still makes the checkout of
        // a ref that changes the file die. A content comparison would wave it through.
        $this->fixture(releaseChangesHtaccess: true);
        $this->git('update-index', '--skip-worktree', 'public/.htaccess');
        touch($this->repo.'/public/.htaccess', time() + 120);

        [$reachedMaintenance, $out] = $this->attempt($via, 'v1');

        $this->assertFalse($reachedMaintenance, 'refused before maintenance');
        $this->assertStringContainsString("--no-skip-worktree --no-assume-unchanged 'public/.htaccess'", $out);
    }

    #[DataProvider('entryPoints')]
    public function test_it_refuses_a_checkout_that_would_die_on_an_edited_assume_unchanged_file(string $via): void
    {
        // Same failure mode by the other flag: git ignores the edit (so the
        // clean-tree check passes) but the checkout of a ref changing c.txt dies.
        $this->fixture();
        File::append($this->repo.'/c.txt', "edited on the server\n");
        $this->git('update-index', '--assume-unchanged', 'c.txt');

        [$reachedMaintenance, $out] = $this->attempt($via, 'v1');

        $this->assertFalse($reachedMaintenance, 'refused before maintenance');
        $this->assertStringContainsString("--no-assume-unchanged 'c.txt'", $out, 'the instruction clears THIS flag too');
        $this->assertSame([], $this->backedUp());
    }

    #[DataProvider('entryPoints')]
    public function test_a_missing_flagged_file_is_not_refused(string $via): void
    {
        // Flagged skip-worktree but deleted on disk (e.g. an nginx host that has
        // no use for .htaccess): the checkout simply restores it — no refusal.
        $this->fixture(releaseChangesHtaccess: true);
        $this->git('update-index', '--skip-worktree', 'public/.htaccess');
        File::delete($this->repo.'/public/.htaccess');

        [$reachedMaintenance, $out] = $this->attempt($via, 'v1');

        $this->assertTrue($reachedMaintenance, $out);
        $this->assertStringNotContainsString('--no-skip-worktree', $out);
    }

    #[DataProvider('entryPoints')]
    public function test_it_refuses_uncommitted_tracked_changes_before_touching_anything(string $via): void
    {
        $this->fixture();
        File::append($this->repo.'/b.txt', "hand hotfix\n");

        [$reachedMaintenance, $out] = $this->attempt($via, 'v1');

        $this->assertFalse($reachedMaintenance);
        $this->assertMatchesRegularExpression('/not clean|δεν είναι καθαρό/', $out);
        $this->assertSame([], $this->backedUp());
        $this->assertStringContainsString('hand hotfix', File::get($this->repo.'/b.txt'), 'the hotfix survives');
    }

    // ─────────────────────────────── shell scripts ───────────────────────────────

    #[DataProvider('scripts')]
    public function test_a_copy_that_fails_midway_leaves_no_copies_behind(string $script): void
    {
        // A `cp` that fails for ign.txt only — after a.txt, dir and foo/bar were
        // already copied (ls-tree order). The partial copies must go too.
        $this->fixture();
        $real = (new ExecutableFinder)->find('cp');
        File::ensureDirectoryExists($this->repo.'/.bin');
        File::put($this->repo.'/.bin/cp', "#!/usr/bin/env bash\nfor a in \"\$@\"; do [[ \"\$a\" == ign.txt ]] && exit 1; done\nexec {$real} \"\$@\"\n");
        chmod($this->repo.'/.bin/cp', 0755);

        [$reachedMaintenance, $out] = $this->viaScript($script, 'v1', [
            'PATH' => $this->repo.'/.bin:'.getenv('PATH'),
            'ALLOW_DOWNGRADE' => '1',   // update.sh: get past the downgrade guard to the copies
        ]);

        $this->assertFalse($reachedMaintenance);
        $this->assertStringContainsString("Could not back up 'ign.txt'", $out);
        $this->assertSame([], $this->backedUp(), 'the partial copies were removed');
        $this->assertOperatorFilesIntact();
    }

    #[DataProvider('scripts')]
    public function test_an_unreadable_directory_in_the_way_is_refused_with_a_message(string $script): void
    {
        // `find` can't read all of foo/ (which v1 replaces with a file, deleting
        // it): refuse with a message instead of dying silently under `set -e`. A
        // fake `find` — the suite may run as root, which can read everything.
        $this->fixture();
        $real = (new ExecutableFinder)->find('find');
        File::ensureDirectoryExists($this->repo.'/.bin');
        File::put($this->repo.'/.bin/find', "#!/usr/bin/env bash\nif [[ \"\$1\" == ./foo ]]; then echo 'find: ./foo/priv: Permission denied' >&2; exit 1; fi\nexec {$real} \"\$@\"\n");
        chmod($this->repo.'/.bin/find', 0755);

        [$reachedMaintenance, $out] = $this->viaScript($script, 'v1', [
            'PATH' => $this->repo.'/.bin:'.getenv('PATH'),
            'ALLOW_DOWNGRADE' => '1',
        ]);

        $this->assertFalse($reachedMaintenance);
        $this->assertStringContainsString("Can't read everything inside 'foo/'", $out);
        $this->assertSame([], $this->backedUp());
    }

    public function test_rollback_sh_refuses_an_unknown_ref_before_touching_anything(): void
    {
        $this->fixture();

        [$reachedMaintenance, $out] = $this->viaScript('rollback.sh', 'no-such-ref');

        $this->assertFalse($reachedMaintenance);
        $this->assertStringContainsString('Unknown ref: no-such-ref', $out);
        $this->assertNull($this->phpCalls(), 'refused before maintenance — `artisan` was never called');
    }

    public function test_rollback_sh_never_refuses_over_untracked_files_and_leaves_the_rest_alone(): void
    {
        // v2 = HEAD: nothing the checkout touches collides with the operator's
        // untracked/ignored leftovers — and they must not block it either (a bare
        // `git status --porcelain` would count them: the shield:generate deadlock).
        $this->fixture();

        [$reachedMaintenance, $out] = $this->viaScript('rollback.sh', 'v2');

        $this->assertTrue($reachedMaintenance, $out);
        $this->assertStringNotContainsString('not clean', $out);
        $this->assertSame([], $this->backedUp(), 'nothing the checkout would destroy');
    }

    public function test_rollback_sh_ignores_an_environment_managed_htaccess_edit_the_target_does_not_change(): void
    {
        // cPanel's MultiPHP rewrites public/.htaccess: that must not read as a
        // dirty tree (same skip-worktree as update.sh), or no rollback could run.
        $this->fixture();
        File::append($this->repo.'/public/.htaccess', "# php -- BEGIN cPanel-generated handler\n");

        [$reachedMaintenance, $out] = $this->viaScript('rollback.sh', 'v2');

        $this->assertTrue($reachedMaintenance, $out);
        $this->assertStringNotContainsString('--no-skip-worktree', $out);
    }

    public function test_an_update_refused_by_a_later_check_leaves_no_copies(): void
    {
        // v1 is an ancestor of HEAD → the downgrade guard refuses. The operator's
        // files WOULD be copied — but only once every refuse-check has passed.
        $this->fixture();

        [$reachedMaintenance, $out] = $this->viaScript('update.sh', 'v1', ['ALLOW_DOWNGRADE' => '0']);

        $this->assertFalse($reachedMaintenance);
        $this->assertStringContainsString('refusing to downgrade', $out);
        $this->assertSame([], $this->backedUp(), 'a refused deploy must not leave copies behind');
        $this->assertStringNotContainsString('Copies kept', $out);
    }

    // ─────────────────────────────── in-app paths ────────────────────────────────

    public function test_in_app_a_copy_that_fails_midway_leaves_no_copies_behind(): void
    {
        // Freeze the clock so the backup dir name is known, and stand a DIRECTORY
        // where ign.txt's copy must go: a.txt, dir and foo/bar copy first, then
        // ign.txt fails — the partial copies must go too.
        $this->fixture();
        $this->travelTo(now()->setDate(2026, 1, 2)->setTime(3, 4, 5));
        File::ensureDirectoryExists($this->repo.'/storage/app/deploy-untracked/'.now()->format('Ymd-His').'/ign.txt');

        [$reachedMaintenance, $out] = $this->attempt('in-app rollback', 'v1');

        $this->assertFalse($reachedMaintenance);
        $this->assertStringContainsString("αντίγραφο του 'ign.txt'", $out);
        $this->assertSame([], $this->backedUp(), 'the partial copies were removed');
    }

    public function test_in_app_rollback_refuses_an_unknown_ref(): void
    {
        $this->fixture();

        [$reachedMaintenance, $out] = $this->attempt('in-app rollback', 'no-such-ref');

        $this->assertFalse($reachedMaintenance);
        $this->assertStringContainsString('Άγνωστο target ref', $out);
    }

    public function test_in_app_refusal_names_the_ref_the_operator_picked_under_its_own_step(): void
    {
        $this->fixture(releaseChangesHtaccess: true);
        File::append($this->repo.'/public/.htaccess', "# cPanel handler\n");
        $this->git('update-index', '--skip-worktree', 'public/.htaccess');

        $run = $this->inAppRun(UpdateRun::KIND_ROLLBACK, 'v1');

        $this->assertSame('guard', $run->phase, 'shown as the checkout dry-run check, not as «copies»');
        $this->assertStringContainsString('Το checkout του v1 ', (string) $run->error_message, 'the ref, not a raw SHA');
    }

    // ─────────────────────────────── runners ───────────────────────────────

    /** @return array{bool, string} [reached `artisan down`, everything it said] */
    private function attempt(string $via, string $ref): array
    {
        return match ($via) {
            'rollback.sh' => $this->viaScript('rollback.sh', $ref),
            'update.sh' => $this->viaScript('update.sh', $ref, ['ALLOW_DOWNGRADE' => '1']),
            'in-app rollback' => $this->viaInApp(UpdateRun::KIND_ROLLBACK, $ref),
            'in-app update' => $this->viaInApp(UpdateRun::KIND_UPDATE, $ref),
        };
    }

    /**
     * @param  array<string, string|false>  $env
     * @return array{bool, string}
     */
    private function viaScript(string $script, string $ref, array $env = []): array
    {
        $process = new Process(['bash', 'deploy/'.$script, $ref], $this->repo, array_merge([
            'PHP' => $this->repo.'/fakephp',
            'COMPOSER' => 'false',
            'ALLOW_DOWNGRADE' => '0',
            'QUEUE_STOP_CMD' => false,
            'QUEUE_START_CMD' => false,
        ], self::CLEAN_GIT_ENV, $env), null, 60);
        $process->run();

        $out = (string) preg_replace('/\e\[[0-9;]*m/', '', $process->getOutput().$process->getErrorOutput());

        return [str_contains((string) $this->phpCalls(), 'artisan down'), $out];
    }

    /** @return array{bool, string} */
    private function viaInApp(string $kind, string $ref): array
    {
        $run = $this->inAppRun($kind, $ref);

        return [$run->phase === 'maintenance', $run->error_message."\n".$run->output];
    }

    /** Run a queued in-app update/rollback to $ref with base_path() pointed at the fixture repo. */
    private function inAppRun(string $kind, string $ref): UpdateRun
    {
        config(['ekdosi.updates.allow_in_app_apply' => true, 'ekdosi.updates.token' => '']);
        File::put($this->repo.'/snap.sql.gz', 'snapshot');
        if ($kind === UpdateRun::KIND_UPDATE) {
            $this->git('remote', 'add', 'origin', $this->repo);   // runPhp fetches first
        }

        $run = UpdateRun::create([
            'status' => UpdateRun::STATUS_QUEUED,
            'kind' => $kind,
            'strategy' => UpdateRun::STRATEGY_PHP,
            'to_ref' => $ref,
            'restore_snapshot' => $this->repo.'/snap.sql.gz',
        ]);

        $this->app->setBasePath($this->repo);
        Artisan::call('ekdosi:self-update', ['--run' => $run->id]);

        return $run->fresh();
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
        foreach (['deploy', 'public', 'dir', 'l'] as $dir) {
            File::ensureDirectoryExists($this->repo.'/'.$dir);
        }
        File::copy(base_path('deploy/rollback.sh'), $this->repo.'/deploy/rollback.sh');
        File::copy(base_path('deploy/update.sh'), $this->repo.'/deploy/update.sh');
        File::put($this->repo.'/fakephp', "#!/usr/bin/env bash\necho \"\$*\" >> \"\$(pwd)/php.log\"\nexit 1\n");
        chmod($this->repo.'/fakephp', 0755);

        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'test');
        File::ensureDirectoryExists($this->repo.'/.git/info');
        File::append($this->repo.'/.git/info/exclude', "deploy/\nfakephp\nphp.log\nsnap.sql.gz\n.bin/\n");

        // v1 — the older ref.
        File::put($this->repo.'/a.txt', "v1 a\n");
        File::put($this->repo.'/ign.txt', "v1 ign\n");
        File::put($this->repo.'/foo', "v1 foo\n");
        File::put($this->repo.'/dir/x', "v1 x\n");
        File::put($this->repo.'/l/x', "v1 lx\n");
        File::put($this->repo.'/l/y', "v1 ly\n");
        File::put($this->repo.'/b.txt', "keep\n");
        File::put($this->repo.'/c.txt', "c v1\n");
        File::put($this->repo.'/public/.htaccess', $releaseChangesHtaccess ? "# laravel v1\n" : "# laravel\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'v1');
        $this->git('tag', 'v1');

        // v2 — HEAD.
        $this->git('rm', '-q', 'a.txt', 'foo', 'dir/x', 'l/x', 'l/y');
        $this->git('rm', '-q', '--cached', 'ign.txt');
        File::put($this->repo.'/.gitignore', "ign.txt\n");
        File::put($this->repo.'/c.txt', "c v2\n");
        if ($releaseChangesHtaccess) {
            File::put($this->repo.'/public/.htaccess', "# laravel v2\n");
        }
        $this->git('add', '.gitignore', 'c.txt', 'public/.htaccess');
        $this->git('commit', '-q', '-m', 'v2');
        $this->git('tag', 'v2');

        // The operator's own things, standing where v1 would put its tracked ones.
        foreach (['dir', 'l'] as $dir) {
            if (is_dir($this->repo.'/'.$dir) && ! is_link($this->repo.'/'.$dir)) {
                rmdir($this->repo.'/'.$dir);
            }
        }
        File::ensureDirectoryExists($this->repo.'/foo');
        File::ensureDirectoryExists($this->repo.'/shared');
        File::put($this->repo.'/shared/y', "SHARED-Y\n");
        symlink('shared', $this->repo.'/l');
        foreach (self::AT_RISK as $path => $content) {
            if (! str_starts_with($content, '-> ')) {
                File::put($this->repo.'/'.$path, $content);
            }
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

    /** @return array<string, string> backed-up path => content, or "-> target" for a symlink */
    private function backedUp(): array
    {
        $root = $this->repo.'/storage/app/deploy-untracked';
        if (! is_dir($root)) {
            return [];
        }

        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            [, $path] = explode('/', substr($file->getPathname(), strlen($root) + 1), 2);   // drop <timestamp>/
            $found[$path] = $file->isLink() ? '-> '.readlink($file->getPathname()) : File::get($file->getPathname());
        }
        ksort($found);

        return $found;
    }

    private function assertOperatorFilesIntact(): void
    {
        foreach (self::AT_RISK as $path => $content) {
            $actual = str_starts_with($content, '-> ')
                ? '-> '.readlink($this->repo.'/'.$path)
                : File::get($this->repo.'/'.$path);
            $this->assertSame($content, $actual, "{$path} was not checked out over");
        }
    }

    private function phpCalls(): ?string
    {
        $log = $this->repo.'/php.log';

        return is_file($log) ? File::get($log) : null;
    }
}
