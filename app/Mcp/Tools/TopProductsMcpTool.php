<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\TopProductsTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see TopProductsTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class TopProductsMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return TopProductsTool::class;
    }
}
