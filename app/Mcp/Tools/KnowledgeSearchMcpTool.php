<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\KnowledgeSearchTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see KnowledgeSearchTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class KnowledgeSearchMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return KnowledgeSearchTool::class;
    }

    /** The KB is global (not tenant data), so there's nothing to fan out over «all». */
    protected function allowsFanOut(): bool
    {
        return false;
    }
}
