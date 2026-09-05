<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\IncomeVsExpenseTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see IncomeVsExpenseTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class IncomeVsExpenseMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return IncomeVsExpenseTool::class;
    }
}
