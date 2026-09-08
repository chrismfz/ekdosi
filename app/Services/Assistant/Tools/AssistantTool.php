<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;

/**
 * A tenant-safe, permission-checked capability the AI «Βοηθός» can call. The
 * model never sees SQL or a DB handle — only this fixed set of named tools, and
 * the harness (not the model) enforces tenant + permission on every call.
 *
 * Each tool returns STRUCTURED data the model summarises in Greek (it must cite
 * the numbers the tool returned, never invent them).
 */
interface AssistantTool
{
    /** Snake-case tool name the model calls (e.g. `count_sales`). */
    public function name(): string;

    /** Prescriptive description — states WHEN to call it (measurable lift). */
    public function description(): string;

    /** JSON-schema for the tool input (Anthropic `input_schema`). */
    public function inputSchema(): array;

    /** Shield permission required to run it, or null for no gate. */
    public function permission(): ?string;

    /**
     * Run the tool for the ambient tenant. Receives the validated input map;
     * returns a JSON-serialisable structured result.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(Company $tenant, array $input): array;
}
