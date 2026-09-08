<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\SendCustomerStatementTool;

/**
 * MCP surface for {@see SendCustomerStatementTool} — logic lives there.
 *
 * PROPOSE-ONLY over MCP: it stages an AiPendingAction and returns its id; it does
 * NOT send. Nothing leaves ekdosi until an operator confirms the action inside
 * the panel (there is no external auto-confirm — legally/outward-facing actions
 * stay human-in-the-loop). Deliberately NOT annotated read-only: it writes a
 * pending-action row.
 */
class SendCustomerStatementMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return SendCustomerStatementTool::class;
    }

    /** A write action never fans out over "all" companies — requires a specific one. */
    protected function allowsFanOut(): bool
    {
        return false;
    }
}
