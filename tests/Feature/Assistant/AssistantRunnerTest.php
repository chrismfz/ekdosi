<?php

namespace Tests\Feature\Assistant;

use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Assistant\AssistantRunner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The AI «Βοηθός» tool-use loop: a mocked Anthropic Messages API exercises the
 * full round-trip (model asks for a tool → harness runs it → model answers),
 * usage logging, and the monthly cap gate — no network.
 */
class AssistantRunnerTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ekdosi.ai.enabled' => true, 'services.anthropic.key' => 'sk-test']);

        $this->tenant = Company::create([
            'name' => 'AI OE', 'slug' => 'ai-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'ai_assistant_enabled' => true,
        ]);

        Gate::before(fn () => true); // bypass policies → tools run
        $this->user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    private function runner(): AssistantRunner
    {
        return app(AssistantRunner::class);
    }

    public function test_full_tool_round_trip_logs_usage_and_returns_the_answer(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'stop_reason' => 'tool_use',
                    'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'count_sales', 'input' => (object) []]],
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
                ])
                ->push([
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => 'Δεν έκοψες πωλήσεις αυτόν τον μήνα.']],
                    'usage' => ['input_tokens' => 150, 'output_tokens' => 30],
                ]),
        ]);

        $res = $this->runner()->ask($this->tenant, $this->user, 'Πόσες πωλήσεις έκοψα;');

        $this->assertFalse($res['blocked']);
        $this->assertStringContainsString('πωλήσεις', $res['reply']);
        // Two API turns → two usage rows, 300 tokens total.
        $this->assertSame(2, AiUsageLog::where('company_id', $this->tenant->id)->count());
        $this->assertSame(300, (int) AiUsageLog::where('company_id', $this->tenant->id)->sum(\DB::raw('input_tokens + output_tokens')));
    }

    public function test_monthly_cap_blocks_before_any_api_call(): void
    {
        Http::fake();
        $this->tenant->update(['ai_monthly_token_cap' => 500]);
        AiUsageLog::create([
            'company_id' => $this->tenant->id, 'user_id' => $this->user->id, 'model' => 'claude-sonnet-4-6',
            'input_tokens' => 400, 'output_tokens' => 200, // 600 ≥ 500
        ]);

        $res = $this->runner()->ask($this->tenant, $this->user, 'Πόσες πωλήσεις;');

        $this->assertTrue($res['blocked']);
        $this->assertStringContainsString('όριο', $res['reply']);
        Http::assertNothingSent();
    }

    public function test_disabled_tenant_is_refused_without_api_call(): void
    {
        Http::fake();
        $this->tenant->update(['ai_assistant_enabled' => false]);

        $res = $this->runner()->ask($this->tenant, $this->user, 'Γεια');

        $this->assertTrue($res['blocked']);
        Http::assertNothingSent();
    }
}
