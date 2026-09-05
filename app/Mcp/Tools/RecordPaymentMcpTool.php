<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\Tools\RecordPaymentTool;

/**
 * MCP surface for {@see RecordPaymentTool} — logic lives there.
 *
 * PROPOSE-ONLY over MCP: stages an AiPendingAction the operator confirms inside
 * ekdosi (the Payment is created only on confirm). NOT read-only — it writes a
 * pending-action row.
 */
class RecordPaymentMcpTool extends AssistantMcpTool
{
    protected function assistantToolClass(): string
    {
        return RecordPaymentTool::class;
    }

    /** A money write never fans out over "all" companies — requires a specific one. */
    protected function allowsFanOut(): bool
    {
        return false;
    }
}
