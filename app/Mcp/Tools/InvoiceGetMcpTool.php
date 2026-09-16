<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\InvoiceGetTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see InvoiceGetTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class InvoiceGetMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return InvoiceGetTool::class;
    }
}
