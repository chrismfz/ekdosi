<?php

namespace App\Mcp\Support;

use App\Services\Assistant\Tools\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Bridges an {@see AssistantTool}'s raw
 * Anthropic-style `input_schema` array into Laravel MCP's typed {@see JsonSchema}
 * builder, so the SAME tool definition drives BOTH transports — the in-app
 * «Βοηθός» (which sends the raw array straight to the Messages API) and the MCP
 * server (which needs the builder objects). One source of truth for a tool's
 * arguments; no per-channel schema to keep in sync.
 *
 * Handles the shapes our tools actually use (object with `properties`, each a
 * string/number/integer/boolean with an optional `description`/`enum`/`default`,
 * plus a top-level `required` list). Unknown types fall back to string.
 */
class McpSchema
{
    /**
     * @param  array<string, mixed>  $raw  the tool's inputSchema() array
     * @return array<string, Type>
     */
    public static function fromRaw(JsonSchema $schema, array $raw): array
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($raw['properties'] ?? null) ? $raw['properties'] : [];
        /** @var list<string> $required */
        $required = is_array($raw['required'] ?? null) ? $raw['required'] : [];

        $out = [];
        foreach ($properties as $name => $def) {
            $def = is_array($def) ? $def : [];
            $type = is_string($def['type'] ?? null) ? $def['type'] : 'string';

            $node = match ($type) {
                'integer' => $schema->integer(),
                'number' => $schema->number(),
                'boolean' => $schema->boolean(),
                'array' => $schema->array(),
                'object' => $schema->object(),
                default => $schema->string(),
            };

            if (! empty($def['description']) && is_string($def['description'])) {
                $node = $node->description($def['description']);
            }
            if (isset($def['enum']) && is_array($def['enum'])) {
                $node = $node->enum($def['enum']);
            }
            if (array_key_exists('default', $def)) {
                $node = $node->default($def['default']);
            }
            if (in_array($name, $required, true)) {
                $node = $node->required();
            }

            $out[$name] = $node;
        }

        return $out;
    }
}
