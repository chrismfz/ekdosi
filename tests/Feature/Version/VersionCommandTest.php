<?php

namespace Tests\Feature\Version;

use App\Support\BuildInfo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VersionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.version' => '1.1.0', 'ekdosi.updates.repo' => 'chrismfz/ekdosi', 'ekdosi.updates.enabled' => true]);
        Cache::flush();
        @unlink(storage_path('app/build.json'));
        BuildInfo::flush();
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/build.json'));
        BuildInfo::flush();
        parent::tearDown();
    }

    public function test_prints_the_build_identity(): void
    {
        $this->artisan('ekdosi:version')
            ->assertSuccessful()
            ->expectsOutputToContain('v1.1.0');
    }

    public function test_json_output_is_valid_and_structured(): void
    {
        $this->withoutMockingConsoleOutput();
        $this->artisan('ekdosi:version', ['--json' => true]);
        $out = Artisan::output();

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertSame('1.1.0', $decoded['build']['version']);
        $this->assertArrayHasKey('update', $decoded);
    }

    public function test_check_reports_a_newer_release(): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response(['tag_name' => 'v2.0.0'], 200),
        ]);

        $this->artisan('ekdosi:version', ['--check' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('v2.0.0');
    }
}
