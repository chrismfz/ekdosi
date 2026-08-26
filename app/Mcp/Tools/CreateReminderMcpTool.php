<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\CreateReminderTool;

/**
 * MCP surface for {@see CreateReminderTool} — logic lives there.
 *
 * PROPOSE-ONLY over MCP: stages an AiPendingAction the operator confirms inside
 * ekdosi. Deliberately NOT annotated read-only: it writes a pending-action row.
 */
class CreateReminderMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return CreateReminderTool::class;
    }

    /** A write action never fans out over "all" companies — requires a specific one. */
    protected function allowsFanOut(): bool
    {
        return false;
    }
}
