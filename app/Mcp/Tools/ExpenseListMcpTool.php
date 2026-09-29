<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\ExpenseListTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see ExpenseListTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class ExpenseListMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return ExpenseListTool::class;
    }
}
