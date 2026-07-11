<?php

namespace Tests\Feature\Version;

use App\Services\Updates\UpdateChecker;
use App\Support\BuildInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The READ-ONLY update check: compares the deployed version against the repo's
 * latest GitHub release/tag, degrades gracefully offline, and caches success.
 */
class UpdateCheckerTest extends TestCase
{
    private string $buildPath;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.version' => '1.1.0',
            'ekdosi.updates.enabled' => true,
            'ekdosi.updates.repo' => 'chrismfz/ekdosi',
            'ekdosi.updates.token' => null,
            'ekdosi.updates.cache_hours' => 6,
            'ekdosi.updates.timeout' => 8,
        ]);
        Cache::flush();
        $this->buildPath = storage_path('app/build.json');
        @unlink($this->buildPath);
        BuildInfo::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->buildPath);
        BuildInfo::flush();
        parent::tearDown();
    }

    private function writeSha(string $sha): void
    {
        @mkdir(dirname($this->buildPath), 0777, true);
        file_put_contents($this->buildPath, json_encode(['sha' => $sha, 'committed_at' => '2026-07-11T12:00:00+00:00', 'ref' => 'main']));
        BuildInfo::flush();
    }

    private function checker(): UpdateChecker
    {
        return new UpdateChecker(new BuildInfo);
    }

    public function test_flags_a_newer_release_as_available(): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.0',
                'html_url' => 'https://github.com/chrismfz/ekdosi/releases/tag/v1.2.0',
                'published_at' => '2026-07-01T00:00:00Z',
            ], 200),
        ]);

        $s = $this->checker()->check();

        $this->assertTrue($s['ok']);
        $this->assertTrue($s['update_available']);
        $this->assertSame('v1.2.0', $s['latest_version']);
        $this->assertSame('1.1.0', $s['current_version']);
        $this->assertNotNull($s['url']);
        $this->assertNull($s['commits_behind']);   // no sha known → no compare
    }

    public function test_same_version_is_not_an_update(): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response(['tag_name' => 'v1.1.0'], 200),
        ]);

        $s = $this->checker()->check();

        $this->assertTrue($s['ok']);
        $this->assertFalse($s['update_available']);
    }

    public function test_reports_commits_behind_when_sha_is_known(): void
    {
        $this->writeSha('a1b2c3d');
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response(['tag_name' => 'v1.4.0'], 200),
            'api.github.com/repos/*/compare/*' => Http::response(['ahead_by' => 7, 'behind_by' => 0, 'status' => 'behind'], 200),
        ]);

        $s = $this->checker()->check();

        $this->assertTrue($s['update_available']);
        $this->assertSame(7, $s['commits_behind']);
    }

    public function test_falls_back_to_tags_when_no_releases_and_picks_highest_semver(): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([], 404),
            'api.github.com/repos/*/tags*' => Http::response([
                ['name' => 'v1.0.0'], ['name' => 'v1.3.0'], ['name' => 'v1.2.0'],
            ], 200),
        ]);

        $s = $this->checker()->check();

        $this->assertTrue($s['ok']);
        $this->assertSame('v1.3.0', $s['latest_version']);   // highest, not first
        $this->assertTrue($s['update_available']);
    }

    public function test_network_error_degrades_gracefully_without_throwing(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response('boom', 500),
        ]);

        $s = $this->checker()->check();

        $this->assertFalse($s['ok']);
        $this->assertNotNull($s['error']);
        $this->assertSame('1.1.0', $s['current_version']);   // build still reported
    }

    public function test_disabled_check_reports_disabled(): void
    {
        config(['ekdosi.updates.enabled' => false]);
        Http::fake();

        $s = $this->checker()->check();

        $this->assertFalse($s['ok']);
        $this->assertFalse($s['enabled']);
        Http::assertNothingSent();
    }

    public function test_successful_result_is_cached(): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response(['tag_name' => 'v1.2.0'], 200),
        ]);

        $this->checker()->check();
        $this->checker()->check();   // second call must hit the cache, not the API

        Http::assertSentCount(1);
    }

    public function test_fresh_bypasses_the_cache(): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response(['tag_name' => 'v1.2.0'], 200),
        ]);

        $this->checker()->check();
        $this->checker()->check(fresh: true);

        Http::assertSentCount(2);
    }
}
