<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\SearchInvoicesTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see SearchInvoicesTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class SearchInvoicesMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return SearchInvoicesTool::class;
    }
}
