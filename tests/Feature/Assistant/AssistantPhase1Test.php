<?php

namespace Tests\Feature\Assistant;

use App\Filament\Pages\Assistant;
use App\Livewire\AssistantWidget;
use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Assistant\AiPricing;
use App\Services\Assistant\AiUsageMeter;
use App\Services\Assistant\AssistantRunner;
use App\Services\Assistant\ToolRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        // No Gate::before → a plain user has neither View:Invoice nor View:Customer
        // nor View:ActivityFeed, so only the UNGATED tools are offered — `knowledge_search`
        // (curated app KB, safe for anyone), `app_version` (public build/update info) and
        // `create_reminder` (a self-scoped reminder); every permission-gated read/write
        // tool is hidden. Order = registration order.
        $registry = new ToolRegistry;
        $names = array_column($registry->definitionsFor($this->user), 'name');
        $this->assertSame(['knowledge_search', 'app_version', 'create_reminder'], $names);

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

    public function test_count_sales_is_isolated_to_the_bound_tenant(): void
    {
        Gate::before(fn () => true);
        $a = $this->tenantWithSale(124.0);
        $this->tenantWithSale(999.0); // a SECOND tenant with a bigger sale

        // The real path: the registry binds the tenant via CompanyContext::actAs.
        // Even though the ambient context is the setUp tenant, the result is A's
        // ONLY — never tenant B's 999.
        $res = (new ToolRegistry)->run($a, $this->user, 'count_sales', []);

        $this->assertSame(1, $res['invoice_count']);
        $this->assertEqualsWithDelta(124.0, $res['gross_total'], 0.01);
    }

    public function test_api_error_is_handled_gracefully_and_logs_the_reason(): void
    {
        Gate::before(fn () => true);
        Log::spy();
        // The real-world case: a 400 with Anthropic's «credit balance too low».
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low'],
        ], 400)]);

        $res = app(AssistantRunner::class)->ask($this->tenant, $this->user, 'Γεια');

        // Graceful to the user…
        $this->assertTrue($res['blocked']);
        $this->assertStringContainsString('Προσωρινό σφάλμα', $res['reply']);
        // …but the actual Anthropic reason is captured for the operator.
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg, array $ctx): bool => $msg === 'AI API call failed'
                && str_contains((string) $ctx['reason'], 'credit balance')
        );
    }

    public function test_empty_tool_input_is_resent_as_an_object_not_array(): void
    {
        Gate::before(fn () => true);
        // Turn 1: a NO-ARG tool call → input {} which ->json() decodes to a PHP [].
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'outstanding_receivables', 'input' => []]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
            ->push([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Σύνολο 0 €.']],
                'usage' => ['input_tokens' => 20, 'output_tokens' => 5],
            ]),
        ]);

        $res = app(AssistantRunner::class)->ask($this->tenant, $this->user, 'Πόσα μας χρωστάνε;');

        $this->assertStringContainsString('Σύνολο', $res['reply']);
        // The resent assistant tool_use turn must carry input as {} — NEVER «[]»
        // (which Anthropic 400s «input: Input should be an object»).
        Http::assertNotSent(fn ($request) => str_contains((string) json_encode($request->data()), '"input":[]'));
    }

    public function test_prompt_cache_control_toggles_with_config(): void
    {
        Gate::before(fn () => true);

        config(['ekdosi.ai.prompt_cache' => true]);
        $this->fakeReply('ok');
        app(AssistantRunner::class)->ask($this->tenant, $this->user, 'Γεια');
        Http::assertSent(fn ($request) => ($request->data()['cache_control']['type'] ?? null) === 'ephemeral');

        config(['ekdosi.ai.prompt_cache' => false]);
        $this->fakeReply('ok');
        app(AssistantRunner::class)->ask($this->tenant, $this->user, 'Γεια');
        Http::assertSent(fn ($request) => ! isset($request->data()['cache_control']));
    }

    private function tenantWithSale(float $gross): Company
    {
        $t = Company::create([
            'name' => 'T'.uniqid(), 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $pm = PaymentMethod::create(['company_id' => $t->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $type = InvoiceType::create(['company_id' => $t->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'Πελ', 'afm' => (string) random_int(100000000, 999999999)]);
        Invoice::create([
            'company_id' => $t->id, 'invcode' => 'I'.uniqid(), 'code' => 1,
            'invoice_type_id' => $type->id, 'payment_method_id' => $pm->id, 'customer_id' => $cust->id,
            'issued_at' => now(), 'local_status' => 'active',
            'net_total' => round($gross / 1.24, 2), 'gross_total' => $gross, 'header_discount_percent' => 0,
        ]);

        return $t;
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
