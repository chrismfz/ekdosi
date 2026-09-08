<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\SuperAdminMcpTool;
use App\Support\OperatorHealth\OperatorHealthReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The deploy-health snapshot — the SAME report as `php artisan ops:health`,
 * exposed read-only over MCP so an operator can ask «είμαστε εντάξει;» from
 * outside the box. Covers the queue worker (heartbeat + pending/failed jobs),
 * the scheduler/cron (per-task last-run + status + a durable run history),
 * backups, mail, WHMCS, myDATA, disk, and a single distilled `severity`.
 * Cross-tenant infrastructure → super_admin only, read-only.
 */
#[Name('app_health')]
#[Description('ekdosi deploy health, read-only (same data as `php artisan ops:health`): queue worker heartbeat + pending/failed job counts, scheduler/cron per-task last-run & status + recent run history, backups, mail, WHMCS, myDATA, disk usage, and one distilled `severity` (ok/warning/critical). Start here for "is anything wrong with the deployment?". Cross-tenant infra; super-admin only.')]
#[IsReadOnly]
#[IsIdempotent]
class AppHealthTool extends SuperAdminMcpTool
{
    /** No arguments. */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return self::json(app(OperatorHealthReport::class)->build());
    }
}
