<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\SuperAdminMcpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Recent failed queue jobs with their exception head — the first stop when a
 * scheduled task, mail send, WHMCS pull or myDATA submit «δεν έγινε». Reads the
 * `failed_jobs` table (DB queue driver) newest-first: job name, queue, when it
 * failed, and the top of the exception (message + first stack frames). Read-only,
 * super_admin.
 */
#[Name('failed_jobs')]
#[Description('Recent FAILED queue jobs, newest first, with the top of each exception (message + first stack frames), job display name, connection, queue and failed_at. The first stop for "why did the background job / scheduled task / mail / WHMCS / myDATA submit fail?". Optional `limit` (default 20, max 100). Read-only, super-admin.')]
#[IsReadOnly]
#[IsIdempotent]
class FailedJobsTool extends SuperAdminMcpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('How many recent failures to return (default 20, max 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! Schema::hasTable('failed_jobs')) {
            return self::json(['error' => 'Δεν υπάρχει πίνακας failed_jobs (μη-DB queue driver;).', 'failures' => []]);
        }

        $limit = max(1, min((int) ($request->get('limit') ?? 20), 100));

        $rows = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        $failures = $rows->map(function (object $row): array {
            $payload = json_decode((string) $row->payload, true);
            $name = is_array($payload) ? ($payload['displayName'] ?? null) : null;

            return [
                'uuid' => $row->uuid,
                'name' => $name,
                'connection' => $row->connection,
                'queue' => $row->queue,
                'failed_at' => $row->failed_at,
                // Head of the trace: the message + top frames explain the failure
                // without egressing an entire multi-KB stack per row.
                'exception' => mb_substr((string) $row->exception, 0, 1200),
            ];
        })->all();

        return self::json([
            'count' => count($failures),
            'total_failed' => DB::table('failed_jobs')->count(),
            'limit' => $limit,
            'failures' => $failures,
        ]);
    }
}
