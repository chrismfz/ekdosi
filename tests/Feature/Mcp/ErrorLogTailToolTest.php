<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\EkdosiMcpServer;
use App\Mcp\Tools\ErrorLogTailTool;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * error_log_tail surfaces the PHP/FPM/web-server error log — the errors that never
 * reach laravel.log. Two things are locked: (1) it is super_admin-only (like the
 * other ops tools); (2) it resolves PHP's own error_log from ini_get and tails it,
 * so it works regardless of hosting panel with no hardcoded path.
 */
class ErrorLogTailToolTest extends TestCase
{
    use RefreshDatabase;

    private ?string $originalErrorLog = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalErrorLog = ini_get('error_log') ?: '';
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->originalErrorLog);
        parent::tearDown();
    }

    private function company(): Company
    {
        return Company::create([
            'name' => 'ACME', 'slug' => 'acme-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
        ]);
    }

    private function superAdmin(Company $company): User
    {
        $user = User::create(['name' => 'Root', 'email' => 'root-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $company);

        return $user->fresh();
    }

    private function member(Company $company): User
    {
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);

        return $user->fresh();
    }

    public function test_super_admin_tails_the_php_error_log_resolved_from_ini(): void
    {
        // Point PHP's runtime error_log at a temp file (what a hosting panel sets
        // per-pool) and drop a known marker line into it.
        $tmp = tempnam(sys_get_temp_dir(), 'ekdosi-phperr-').'.log';
        file_put_contents($tmp, "[03-Sep-2026 23:59:59] PHP Fatal error:  MARKER-RECURSION-abc123 in Foo.php on line 1\n");
        ini_set('error_log', $tmp);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($this->company()))
            ->tool(ErrorLogTailTool::class, []);

        $response->assertOk();
        $response->assertSee('MARKER-RECURSION-abc123'); // the tailed line
        $response->assertSee($tmp);                       // surfaced as the primary source

        @unlink($tmp);
    }

    public function test_contains_filter_narrows_the_lines(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ekdosi-phperr-').'.log';
        file_put_contents($tmp, "line one keep-me\nline two other\nline three keep-me\n");
        ini_set('error_log', $tmp);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($this->company()))
            ->tool(ErrorLogTailTool::class, ['contains' => 'keep-me']);

        $response->assertOk();
        $response->assertSee('keep-me');
        $response->assertDontSee('line two other');

        @unlink($tmp);
    }

    public function test_an_over_long_single_fatal_line_is_truncated_not_dropped(): void
    {
        // A fatal + stack trace written as ONE line longer than the byte cap must
        // still surface (truncated) — returning «empty» would hide the exact error
        // this tool exists to find.
        $tmp = tempnam(sys_get_temp_dir(), 'ekdosi-phperr-').'.log';
        file_put_contents($tmp, 'PHP Fatal error: START-MARKER '.str_repeat('x', 200000)." END\n");
        ini_set('error_log', $tmp);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($this->company()))
            ->tool(ErrorLogTailTool::class, []);

        $response->assertOk();
        $response->assertSee('START-MARKER');            // the fatal is surfaced…
        $response->assertSee('γραμμή περικομμένη');       // …truncated, not dropped

        @unlink($tmp);
    }

    public function test_diagnoses_the_php_logging_setup(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ekdosi-phperr-').'.log';
        file_put_contents($tmp, "boot\n");
        ini_set('error_log', $tmp);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($this->company()))
            ->tool(ErrorLogTailTool::class, []);

        $response->assertOk();
        $response->assertSee('php_logging');
        $response->assertSee('writable_by_app');

        @unlink($tmp);
    }

    public function test_warns_when_php_is_told_to_log_where_the_app_cannot_write(): void
    {
        // PHP configured to log into a directory that doesn't exist / isn't writable
        // → its fatals are silently DROPPED. The tool must flag this loudly (it is
        // the exact reason a crash can leave no trace), not just say «nothing found».
        ini_set('error_log', '/nonexistent-ekdosi-'.uniqid().'/php-error.log');

        $response = EkdosiMcpServer::actingAs($this->superAdmin($this->company()))
            ->tool(ErrorLogTailTool::class, []);

        $response->assertOk();
        $response->assertSee('ΧΑΝΟΝΤΑΙ'); // «τα PHP fatals ΧΑΝΟΝΤΑΙ» misconfiguration warning
    }

    public function test_not_offered_to_a_tenant_member(): void
    {
        // Error logs can carry sensitive paths/queries — super_admin only.
        $response = EkdosiMcpServer::actingAs($this->member($this->company()))
            ->tool(ErrorLogTailTool::class, []);

        $response->assertHasErrors();
    }
}
