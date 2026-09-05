<?php

namespace App\Services\Assistant;

use App\Models\Company;
use App\Models\User;
use App\Services\Assistant\Tools\AiUsageTool;
use App\Services\Assistant\Tools\AppVersionTool;
use App\Services\Assistant\Tools\AssistantTool;
use App\Services\Assistant\Tools\CountSalesTool;
use App\Services\Assistant\Tools\CreateReminderTool;
use App\Services\Assistant\Tools\FindCustomerTool;
use App\Services\Assistant\Tools\IncomeVsExpenseTool;
use App\Services\Assistant\Tools\LeadsPulseTool;
use App\Services\Assistant\Tools\ListTopDebtorsTool;
use App\Services\Assistant\Tools\OutstandingReceivablesTool;
use App\Services\Assistant\Tools\RecentActivityTool;
use App\Services\Assistant\Tools\RecentInvoicesTool;
use App\Services\Assistant\Tools\SendCustomerStatementTool;
use App\Services\Assistant\Tools\TopProductsTool;
use App\Services\Assistant\Tools\VatSummaryTool;
use App\Services\Assistant\Tools\WhmcsInboxTool;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Gate;

/**
 * The fixed catalogue of tools the AI «Βοηθός» may call, plus the dispatch
 * harness. The model picks a tool by name; THIS class — never the model —
 * enforces the user's Shield permission and runs the body inside
 * CompanyContext::actAs($tenant) so a tool can only ever touch the ambient
 * tenant's data. A denied permission returns a structured «δεν έχετε πρόσβαση»,
 * not data; an unknown tool a structured error. Read tools answer directly;
 * WRITE tools only PREPARE an AiPendingAction the operator confirms (the tool
 * never performs the side effect — see AiActionExecutor).
 */
class ToolRegistry
{
    /** @var list<AssistantTool> */
    private array $tools;

    public function __construct()
    {
        $this->tools = [
            new CountSalesTool,
            new OutstandingReceivablesTool,
            new ListTopDebtorsTool,
            new FindCustomerTool,
            new RecentInvoicesTool,
            new VatSummaryTool,
            new RecentActivityTool,
            new LeadsPulseTool,
            new IncomeVsExpenseTool,
            new TopProductsTool,
            new WhmcsInboxTool,
            new AiUsageTool,
            new AppVersionTool,
            // Write tools — PREPARE only; the operator confirms before execution.
            new SendCustomerStatementTool,
            new CreateReminderTool,
        ];
    }

    /**
     * Every registered tool, permission-agnostic — the single catalogue both the
     * in-app chat and the external MCP surface are built from. Used by the parity
     * guard (McpAssistantParityTest) that keeps the two channels in lock-step.
     *
     * @return list<AssistantTool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * The Anthropic `tools` payload (name / description / input_schema), limited
     * to the tools THIS user may run — so the model is never offered a capability
     * the operator lacks.
     *
     * @return list<array<string, mixed>>
     */
    public function definitionsFor(User $user): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            if (! $this->userMay($user, $tool)) {
                continue;
            }
            $out[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $tool->inputSchema(),
            ];
        }

        return $out;
    }

    /**
     * Run a model-requested tool. Returns the structured result, or a structured
     * error (`error` key) on unknown-tool / denied-permission / failure — never
     * throws into the loop.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(Company $tenant, User $user, string $name, array $input): array
    {
        $tool = $this->find($name);
        if ($tool === null) {
            return ['error' => "Άγνωστο εργαλείο: {$name}."];
        }
        if (! $this->userMay($user, $tool)) {
            return ['error' => 'Δεν έχετε πρόσβαση σε αυτή τη λειτουργία.'];
        }

        try {
            // The harness binds the tenant — the tool body can't widen scope.
            return app(CompanyContext::class)->actAs($tenant, fn (): array => $tool->run($tenant, $input));
        } catch (\Throwable $e) {
            return ['error' => 'Σφάλμα εκτέλεσης εργαλείου.'];
        }
    }

    private function find(string $name): ?AssistantTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * May this user run this tool? Shared by definitionsFor() (in-app «Βοηθός»)
     * and the MCP adapter's shouldRegister()/run() so BOTH channels gate a tool
     * on exactly the same Shield permission.
     */
    public function userMay(User $user, AssistantTool $tool): bool
    {
        $permission = $tool->permission();
        if ($permission === null) {
            return true;
        }

        // Gate::forUser keeps it 404-storm-safe: a missing permission resolves to
        // false, super_admin bypasses via Gate::before — same as the panel.
        return Gate::forUser($user)->allows($permission);
    }
}
