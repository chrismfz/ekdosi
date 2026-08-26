<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\CountSalesTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see CountSalesTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class CountSalesMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return CountSalesTool::class;
    }
}
