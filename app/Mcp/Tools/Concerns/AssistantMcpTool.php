<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\McpSchema;
use App\Mcp\Support\McpTenantResolver;
use App\Models\User;
use App\Services\Assistant\ToolRegistry;
use App\Services\Assistant\Tools\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * The ONE adapter that exposes an {@see AssistantTool} — the app's single,
 * tenant-safe, permission-checked tool registry — over the external MCP
 * transport. It carries NO business logic of its own: name, description, input
 * schema, permission gate, tenant binding and execution all delegate to the
 * exact same {@see ToolRegistry} the in-app «Βοηθός» uses. So a capability is
 * written ONCE (an AssistantTool) and works on both channels; a concrete
 * subclass here is a 3-line pointer at which tool it wraps.
 *
 *   - name()/description()  → the wrapped tool's own.
 *   - schema()              → the tool's own inputSchema() PLUS a `company` arg
 *                             (slug, or "all" for read tools) — the MCP channel
 *                             has no session tenant, so the target is named here
 *                             and validated server-side (McpTenantResolver).
 *   - shouldRegister()      → offered only if this user holds the tool's Shield
 *                             permission (same as definitionsFor).
 *   - handle()              → resolve the target company/companies, then run via
 *                             ToolRegistry (Gate + CompanyContext::actAs). A
 *                             cross-tenant read is impossible (the company is
 *                             validated, never trusted from prose); "all" fans
 *                             out per company (no merge); write tools refuse "all"
 *                             (blast-radius) and remain propose-only.
 */
abstract class AssistantMcpTool extends Tool
{
    /** @return class-string<AssistantTool> */
    abstract protected function assistantToolClass(): string;

    /** Read tools may fan out over "all" companies; write tools never do. */
    protected function allowsFanOut(): bool
    {
        return true;
    }

    protected function assistant(): AssistantTool
    {
        return app($this->assistantToolClass());
    }

    public function name(): string
    {
        return $this->assistant()->name();
    }

    public function description(): string
    {
        return $this->assistant()->description();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $props = McpSchema::fromRaw($schema, $this->assistant()->inputSchema());
        $props['company'] = $schema->string()->description(
            $this->allowsFanOut()
                ? 'Εταιρεία (slug) για εστίαση· "all" για fan-out σε ΟΛΕΣ όσες έχεις πρόσβαση (per-company αποτέλεσμα). Προαιρετικό — προεπιλογή η μοναδική/δεσμευμένη εταιρεία σου. Δες list_companies για τα slugs.'
                : 'Εταιρεία (slug) για εστίαση. Υποχρεωτικό αν έχεις πρόσβαση σε πολλές· το "all" ΔΕΝ επιτρέπεται σε αυτή την ενέργεια.'
        );

        return $props;
    }

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User
            && app(ToolRegistry::class)->userMay($user, $this->assistant());
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return self::json(['error' => 'Δεν υπάρχει ταυτοποίηση.']);
        }

        $args = (array) $request->all();
        $company = (array_key_exists('company', $args) && is_string($args['company'])) ? $args['company'] : null;
        unset($args['company']);

        $target = app(McpTenantResolver::class)->resolveTargets($user, $company);
        if ($target['error'] !== null) {
            return self::json(['error' => $target['error']]);
        }

        $companies = $target['companies'];

        // Write tools never fan out — a mutating action across every company is a
        // blast-radius foot-gun (mirrors cfm's node="all" write guard). They also
        // stay propose-only regardless (they stage an AiPendingAction).
        if (! $this->allowsFanOut() && ($target['fannedOut'] || count($companies) > 1)) {
            return self::json(['error' => 'Αυτή η ενέργεια απαιτεί συγκεκριμένη εταιρεία — δεν εκτελείται με "all".']);
        }

        $name = $this->assistant()->name();

        if (! $target['fannedOut']) {
            // ToolRegistry::run re-checks the permission and runs the body inside
            // CompanyContext::actAs($tenant) — identical harness to the in-app chat.
            return self::json(app(ToolRegistry::class)->run($companies[0], $user, $name, $args));
        }

        // Fan out: one call per company, aggregated as a per-company map (no merge,
        // no collision — each keyed by its slug).
        $results = [];
        foreach ($companies as $tenant) {
            $results[$tenant->slug] = app(ToolRegistry::class)->run($tenant, $user, $name, $args);
        }

        return self::json([
            'fanned_out' => true,
            'companies' => array_keys($results),
            'results' => $results,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function json(array $data): Response
    {
        return Response::text((string) json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ));
    }
}
