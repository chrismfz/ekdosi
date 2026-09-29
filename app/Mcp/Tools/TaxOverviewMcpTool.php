<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\TaxOverviewTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see TaxOverviewTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class TaxOverviewMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return TaxOverviewTool::class;
    }
}
