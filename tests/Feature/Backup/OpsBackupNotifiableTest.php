<?php

namespace Tests\Feature\Backup;

use App\Models\Company;
use App\Models\User;
use App\Support\Backup\BackupAlertRecipients;
use App\Support\Backup\OpsBackupNotifiable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AUDIT OPS-2: the global spatie/laravel-backup notifications must route to
 * the REAL ops recipients (same chain as the per-company backup alerts), not
 * the package's static 'your@example.com' placeholder.
 */
class OpsBackupNotifiableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function routes_to_the_configured_alert_email(): void
    {
        config(['ekdosi.backup.alert_email' => 'ops@example.gr, dr@example.gr']);

        $this->assertSame(
            ['ops@example.gr', 'dr@example.gr'],
            (new OpsBackupNotifiable)->routeNotificationForMail(),
        );
    }

    #[Test]
    public function falls_back_to_super_admin_users_when_no_email_configured(): void
    {
        config(['ekdosi.backup.alert_email' => null]);

        $company = Company::create([
            'name' => 'Ops', 'slug' => 'ops-'.uniqid(), 'country_code' => 'GR', 'afm' => '800561849',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        $role = Role::findOrCreate('super_admin');
        $admin = User::factory()->create(['email' => 'root@example.gr']);
        $admin->assignRole($role);
        User::factory()->create(['email' => 'operator@example.gr']); // no role — excluded

        $this->assertSame(['root@example.gr'], (new OpsBackupNotifiable)->routeNotificationForMail());
    }

    #[Test]
    public function falls_back_to_the_config_placeholder_when_nothing_resolves(): void
    {
        config(['ekdosi.backup.alert_email' => null]);

        // No super_admins exist → resolver is empty → the spatie config value
        // (a valid-shaped placeholder) is the last resort, never a crash.
        $this->assertSame([], BackupAlertRecipients::resolve());
        $this->assertSame(
            config('backup.notifications.mail.to'),
            (new OpsBackupNotifiable)->routeNotificationForMail(),
        );
    }
}
