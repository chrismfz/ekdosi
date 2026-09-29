<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\E3SnapshotTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** MCP surface for {@see E3SnapshotTool} — logic lives there (single source). */
#[IsReadOnly]
#[IsIdempotent]
class E3SnapshotMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return E3SnapshotTool::class;
    }
}
