<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\WhmcsInboxListTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see WhmcsInboxListTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class WhmcsInboxListMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return WhmcsInboxListTool::class;
    }
}
