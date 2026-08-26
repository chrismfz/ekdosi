<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\OutstandingReceivablesTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see OutstandingReceivablesTool} — logic lives there. */
#[IsReadOnly]
#[IsIdempotent]
class OutstandingReceivablesMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return OutstandingReceivablesTool::class;
    }
}
