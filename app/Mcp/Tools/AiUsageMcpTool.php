<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\AiUsageTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see AiUsageTool} — logic lives there (single source). Read tool,
 *  so `company="all"` fans out per company (the super-admin cross-tenant view over MCP). */
#[IsReadOnly]
#[IsIdempotent]
class AiUsageMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return AiUsageTool::class;
    }
}
