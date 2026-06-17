<?php

namespace Tests\Feature\Assistant;

use App\Filament\Pages\Assistant;
use App\Livewire\AssistantWidget;
use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Assistant\AiPricing;
use App\Services\Assistant\AiUsageMeter;
use App\Services\Assistant\ToolRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

class AssistantPhase1Test extends TestCase
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
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'ai_assistant_enabled' => true,
        ]);
        $this->user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    private function fakeReply(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
        ])]);
    }

    public function test_page_gate_follows_the_enabled_flags(): void
    {
        Gate::before(fn () => true);
        $this->assertTrue(Assistant::canAccess());

        $this->tenant->update(['ai_assistant_enabled' => false]);
        $this->assertFalse(Assistant::canAccess());

        $this->tenant->update(['ai_assistant_enabled' => true]);
        config(['ekdosi.ai.enabled' => false]);
        $this->assertFalse(Assistant::canAccess());
    }

    public function test_page_send_returns_a_reply(): void
    {
        Gate::before(fn () => true);
        $this->fakeReply('Καλησπέρα!');

        Livewire::test(Assistant::class)
            ->set('draft', 'Γεια')
            ->call('send')
            ->assertSee('Καλησπέρα!');
    }

    public function test_widget_persists_conversation_across_mounts_via_session(): void
    {
        Gate::before(fn () => true);
        $this->fakeReply('Δύο τιμολόγια.');

        Livewire::test(AssistantWidget::class)
            ->set('open', true)
            ->set('draft', 'Πόσα;')
            ->call('send')
            ->assertSee('Δύο τιμολόγια.');

        // A FRESH mount (= navigated to another page) rehydrates from session.
        $fresh = Livewire::test(AssistantWidget::class);
        $transcript = $fresh->get('transcript');
        $this->assertCount(2, $transcript); // user + assistant turn restored
        $this->assertSame('Πόσα;', $transcript[0]['text']);
        $this->assertSame('Δύο τιμολόγια.', $transcript[1]['text']);
    }

    public function test_tool_registry_hides_tools_the_user_cannot_run(): void
    {
        // No Gate::before → a plain user has neither View:Invoice nor View:Customer.
        $registry = new ToolRegistry;
        $this->assertSame([], $registry->definitionsFor($this->user));

        $denied = $registry->run($this->tenant, $this->user, 'count_sales', []);
        $this->assertArrayHasKey('error', $denied);

        $unknown = $registry->run($this->tenant, $this->user, 'nope', []);
        $this->assertArrayHasKey('error', $unknown);
    }

    public function test_tool_registry_exposes_tools_to_a_permitted_user(): void
    {
        Gate::before(fn () => true);
        $names = array_column((new ToolRegistry)->definitionsFor($this->user), 'name');
        $this->assertContains('count_sales', $names);
        $this->assertContains('outstanding_receivables', $names);
    }

    public function test_pricing_estimate_from_the_config_map(): void
    {
        $p = app(AiPricing::class);
        // Sonnet input $3/1M → 1M input = $3.00; +1M output @ $15 = $15.
        $this->assertSame(3.0, $p->estimate('claude-sonnet-4-6', ['input_tokens' => 1_000_000]));
        $this->assertSame(15.0, $p->estimate('claude-sonnet-4-6', ['output_tokens' => 1_000_000]));
        $this->assertSame(0.0, $p->estimate('unknown-model', ['input_tokens' => 1_000_000]));
    }

    public function test_meter_cap_and_warning(): void
    {
        $meter = app(AiUsageMeter::class);
        $this->tenant->update(['ai_monthly_token_cap' => 1000]);

        $this->assertFalse($meter->blocked($this->tenant));

        AiUsageLog::create(['company_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'model' => 'claude-sonnet-4-6', 'input_tokens' => 850, 'output_tokens' => 0]); // 85% → warn
        $this->assertTrue($meter->warning($this->tenant));
        $this->assertFalse($meter->blocked($this->tenant));

        AiUsageLog::create(['company_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'model' => 'claude-sonnet-4-6', 'input_tokens' => 200, 'output_tokens' => 0]); // 1050 ≥ 1000
        $this->assertTrue($meter->blocked($this->tenant));
    }
}
