<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base for the ops/debug MCP tools (`app_health`, `failed_jobs`, `log_tail`).
 * Unlike the tenant-scoped {@see AssistantMcpTool} adapters, these read
 * CROSS-TENANT infrastructure (queue, scheduler, failed jobs, the Laravel log),
 * so they are gated to a system super_admin and are NOT bound to a company. They
 * exist so the fleet can be debugged from an external MCP client — «γιατί έσκασε
 * αυτό;» — the way the in-panel System Health page serves that need internally.
 *
 * Sensitivity note: their output (log lines, exception traces) can contain
 * business data and is egressed to whatever MCP client is connected (incl. the
 * claude.ai connector). super_admin-only + bounded output is the guard; the
 * caller is an operator who could already read these on the box.
 */
abstract class SuperAdminMcpTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->isSystemSuperAdmin();
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
