<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\RecentActivityTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see RecentActivityTool} — logic lives there. */
#[IsReadOnly]
#[IsIdempotent]
class RecentActivityMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return RecentActivityTool::class;
    }
}
