<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\WhmcsInboxTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see WhmcsInboxTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class WhmcsInboxMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return WhmcsInboxTool::class;
    }
}
