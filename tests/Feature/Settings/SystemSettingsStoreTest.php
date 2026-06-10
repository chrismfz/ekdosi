<?php

namespace Tests\Feature\Settings;

use App\Models\SystemSetting;
use App\Support\Settings\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deploy-wide typed store: absent key → caller default, present key → override;
 * forget reverts to env-tracking; writes bust the cache.
 */
class SystemSettingsStoreTest extends TestCase
{
    use RefreshDatabase;

    private function store(): SystemSettings
    {
        return app(SystemSettings::class);
    }

    #[Test]
    public function an_absent_key_returns_the_callers_default(): void
    {
        $this->assertTrue($this->store()->bool('schedule.whatever', true));
        $this->assertFalse($this->store()->bool('schedule.whatever', false));
        $this->assertSame(7, $this->store()->int('schedule.n', 7));
        $this->assertNull($this->store()->string('x'));
        $this->assertFalse($this->store()->has('schedule.whatever'));
    }

    #[Test]
    public function a_stored_value_overrides_the_default(): void
    {
        $this->store()->setBool('schedule.mydata_reconcile_enabled', false, null);

        // Default says true, but the override wins.
        $this->assertFalse($this->store()->bool('schedule.mydata_reconcile_enabled', true));
        $this->assertTrue($this->store()->has('schedule.mydata_reconcile_enabled'));
        $this->assertDatabaseHas('system_settings', [
            'key' => 'schedule.mydata_reconcile_enabled', 'value' => '0', 'type' => 'bool',
        ]);
    }

    #[Test]
    public function forget_reverts_to_env_tracking(): void
    {
        $this->store()->setBool('schedule.mail_sweep_enabled', false, null);
        $this->assertFalse($this->store()->bool('schedule.mail_sweep_enabled', true));

        $this->store()->forget('schedule.mail_sweep_enabled');

        $this->assertTrue($this->store()->bool('schedule.mail_sweep_enabled', true)); // back to default
        $this->assertDatabaseMissing('system_settings', ['key' => 'schedule.mail_sweep_enabled']);
    }

    #[Test]
    public function get_casts_by_stored_type(): void
    {
        $this->store()->set('a.int', 42, 'int');
        $this->store()->set('a.json', ['x' => 1], 'json');

        $this->assertSame(42, $this->store()->get('a.int'));
        $this->assertSame(['x' => 1], $this->store()->get('a.json'));
    }

    #[Test]
    public function set_updates_in_place_and_busts_the_cache(): void
    {
        $this->store()->setBool('k', true, null);
        $this->store()->setBool('k', false, null);

        $this->assertSame(1, SystemSetting::where('key', 'k')->count()); // upsert, not duplicate
        $this->assertFalse($this->store()->bool('k', true));
    }

    #[Test]
    public function a_missing_table_falls_back_to_defaults_without_throwing(): void
    {
        // The safety-critical path: routes/console.php reads the store on every
        // schedule:run. A missing/unmigrated table (or any DB hiccup) must yield
        // the caller's default, never an exception that crashes the scheduler.
        $store = app(SystemSettings::class);
        $store->flush(); // drop any cached map first
        Schema::drop('system_settings');

        $this->assertTrue($store->bool('schedule.mail_sweep_enabled', true));
        $this->assertFalse($store->bool('schedule.mail_sweep_enabled', false));
        $this->assertSame(5, $store->int('x', 5));
        $this->assertFalse($store->has('anything'));
    }
}
