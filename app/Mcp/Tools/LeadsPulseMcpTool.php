<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\LeadsPulseTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see LeadsPulseTool} — logic lives there. */
#[IsReadOnly]
#[IsIdempotent]
class LeadsPulseMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return LeadsPulseTool::class;
    }
}
