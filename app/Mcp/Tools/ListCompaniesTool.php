<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpTenantResolver;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * List the companies the caller may act on — the slugs to pass as the `company`
 * argument on the tenant-scoped tools (or "all" to fan out). A system super_admin
 * sees every tenant; everyone else only their own memberships. Read-only; returns
 * no business data, just identity (slug + name), so it is safe for any
 * authenticated user.
 */
#[Name('list_companies')]
#[Description('List the companies you may act on (slug + name) — the values to pass as the "company" argument on the other tools, or "all" to fan out. A super_admin sees every tenant; others see only their own. Read-only.')]
#[IsReadOnly]
#[IsIdempotent]
class ListCompaniesTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        return $request->user() instanceof User;
    }

    /** No arguments. */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $companies = app(McpTenantResolver::class)->accessibleCompanies($user)
            ->map(fn (Company $c): array => ['slug' => $c->slug, 'name' => $c->name])
            ->all();

        return Response::text((string) json_encode([
            'count' => count($companies),
            'companies' => $companies,
            'note' => 'Πέρασε ένα slug ως "company" στα εργαλεία, ή "all" για fan-out (μόνο read tools).',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
