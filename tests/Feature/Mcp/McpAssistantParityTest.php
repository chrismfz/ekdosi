<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\EkdosiMcpServer;
use App\Mcp\Tools\Concerns\AssistantMcpTool;
use App\Services\Assistant\ToolRegistry;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guardrail for the «one core, two transports» design: every AssistantTool in the
 * in-app registry MUST also be exposed over the external MCP server through an
 * AssistantMcpTool adapter — and vice-versa (no adapter points at a tool that no
 * longer exists). Without this, a new capability silently ships to the chat but
 * not to MCP (or the reverse), and the two channels drift.
 *
 * This does NOT cover the MCP-only ops/forensic tools (SuperAdminMcpTool/
 * ForensicMcpTool) — those are deliberately MCP-only and have no chat twin.
 */
class McpAssistantParityTest extends TestCase
{
    /** @return list<string> the class-strings registered on the MCP server. */
    private function registeredMcpTools(): array
    {
        $defaults = (new ReflectionClass(EkdosiMcpServer::class))->getDefaultProperties();

        return array_values((array) ($defaults['tools'] ?? []));
    }

    public function test_every_assistant_tool_has_exactly_one_mcp_adapter(): void
    {
        $registered = $this->registeredMcpTools();
        // If this comes back empty the reflection is reading the wrong thing (e.g. a
        // future Laravel MCP registers tools some other way) — fail loudly with the
        // real cause instead of misreporting «όλα chat-only» below.
        $this->assertNotEmpty(
            $registered,
            'EkdosiMcpServer::$tools came back empty via reflection — the MCP registration mechanism may have changed; update this guardrail.',
        );

        $assistantNames = array_map(
            static fn ($tool): string => $tool->name(),
            app(ToolRegistry::class)->all(),
        );

        // name() delegates to the wrapped AssistantTool, so a duplicate adapter makes
        // this list longer than $assistantNames and the exact-set assertion below
        // catches it too (no separate duplicate test needed).
        $mcpWrappedNames = [];
        foreach ($registered as $class) {
            if (is_subclass_of($class, AssistantMcpTool::class)) {
                $mcpWrappedNames[] = app($class)->name();
            }
        }

        sort($assistantNames);
        sort($mcpWrappedNames);

        $this->assertSame(
            $assistantNames,
            $mcpWrappedNames,
            'AssistantTool ⇄ MCP adapter parity broken. Every App\\Services\\Assistant\\Tools\\*Tool needs a 3-line '
            ."App\\Mcp\\Tools\\*McpTool (extends AssistantMcpTool) registered in EkdosiMcpServer, and no more.\n"
            .'Chat-only: '.implode(', ', array_diff($assistantNames, $mcpWrappedNames) ?: ['—'])."\n"
            .'MCP-only:  '.implode(', ', array_diff($mcpWrappedNames, $assistantNames) ?: ['—']),
        );
    }
}
