<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\DataFreshnessTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see DataFreshnessTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class DataFreshnessMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return DataFreshnessTool::class;
    }
}
