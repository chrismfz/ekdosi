<?php

namespace Tests\Feature\Version;

use App\Support\BuildInfo;
use Tests\TestCase;

/**
 * BuildInfo resolves the deployed identity from storage/app/build.json (the
 * deploy path) and formats the build stamp in the app timezone.
 */
class BuildInfoTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Athens', 'app.version' => '1.2.3']);
        $this->path = storage_path('app/build.json');
        @unlink($this->path);
        BuildInfo::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        BuildInfo::flush();
        parent::tearDown();
    }

    private function writeBuild(array $data): void
    {
        @mkdir(dirname($this->path), 0777, true);
        file_put_contents($this->path, json_encode($data));
        BuildInfo::flush();
    }

    public function test_reads_the_deploy_file_and_formats_the_stamp_in_app_tz(): void
    {
        // 15:01:01 +03:00 (Athens summer time) → the user's canonical example.
        $this->writeBuild([
            'sha' => 'a1b2c3d',
            'committed_at' => '2026-07-11T15:01:01+03:00',
            'ref' => 'v1.2.3',
        ]);

        $b = new BuildInfo;

        $this->assertSame('1.2.3', $b->version());
        $this->assertSame('a1b2c3d', $b->sha());
        $this->assertSame('v1.2.3', $b->ref());
        $this->assertSame('2026.07.11-150101', $b->buildStamp());
        $this->assertSame('v1.2.3 · 2026.07.11-150101 (a1b2c3d)', $b->label());
    }

    public function test_converts_a_utc_commit_time_into_athens_for_the_stamp(): void
    {
        // 12:01:01 UTC == 15:01:01 Athens (summer) → same stamp.
        $this->writeBuild([
            'sha' => 'deadbee',
            'committed_at' => '2026-07-11T12:01:01+00:00',
            'ref' => 'main',
        ]);

        $this->assertSame('2026.07.11-150101', (new BuildInfo)->buildStamp());
    }

    public function test_degrades_to_version_only_without_a_deploy_file(): void
    {
        // No file, and testing env is not local → no git read either.
        $b = new BuildInfo;

        $this->assertNull($b->buildStamp());
        $this->assertNull($b->sha());
        $this->assertSame('v1.2.3', $b->label());
    }

    public function test_corrupt_file_is_ignored_not_fatal(): void
    {
        @mkdir(dirname($this->path), 0777, true);
        file_put_contents($this->path, 'not json{');
        BuildInfo::flush();

        $b = new BuildInfo;

        $this->assertNull($b->buildStamp());
        $this->assertSame('v1.2.3', $b->label());
    }
}
