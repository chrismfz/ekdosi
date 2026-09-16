<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\PeppolUblTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see PeppolUblTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class PeppolUblMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return PeppolUblTool::class;
    }
}
