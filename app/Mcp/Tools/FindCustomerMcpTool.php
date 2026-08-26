<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\FindCustomerTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see FindCustomerTool} — logic lives there. */
#[IsReadOnly]
#[IsIdempotent]
class FindCustomerMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return FindCustomerTool::class;
    }
}
