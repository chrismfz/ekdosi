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
 *   - name()/description()  → the wrapped tool's own (Primitive lets a subclass
 *                             override these methods; no #[Name]/#[Description]
 *                             attribute needed, so nothing is duplicated).
 *   - schema()              → converted from the tool's raw inputSchema().
 *   - shouldRegister()      → the tool is offered to a client ONLY if this user
 *                             holds its Shield permission (same as definitionsFor).
 *   - handle()              → binds the token's tenant server-side, then runs via
 *                             ToolRegistry (Gate + CompanyContext::actAs), so a
 *                             cross-tenant read is structurally impossible and a
 *                             write tool only PREPARES an AiPendingAction (the
 *                             operator confirms it inside ekdosi — MCP.md §2).
 */
abstract class AssistantMcpTool extends Tool
{
    /** @return class-string<AssistantTool> */
    abstract protected function assistantToolClass(): string;

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
        return McpSchema::fromRaw($schema, $this->assistant()->inputSchema());
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

        [$tenant, $error] = app(McpTenantResolver::class)->resolve($user);
        if ($tenant === null) {
            return self::json(['error' => $error ?? 'Δεν προσδιορίστηκε εταιρεία.']);
        }

        // ToolRegistry::run re-checks the permission and runs the body inside
        // CompanyContext::actAs($tenant) — identical harness to the in-app chat.
        $result = app(ToolRegistry::class)->run(
            $tenant,
            $user,
            $this->assistant()->name(),
            (array) $request->all(),
        );

        return self::json($result);
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
