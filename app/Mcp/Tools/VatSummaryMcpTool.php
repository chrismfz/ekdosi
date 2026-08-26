<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\VatSummaryTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see VatSummaryTool} — logic lives there. */
#[IsReadOnly]
#[IsIdempotent]
class VatSummaryMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return VatSummaryTool::class;
    }
}
