<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ForensicMcpTool;
use App\Models\Company;
use App\Services\MyData\ConfigAuditRow;
use App\Services\MyData\MyDataConfigAudit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The `mydata:preflight` configuration audit over MCP — «είμαστε έτοιμοι να
 * κόψουμε;» without a shell. Runs the SAME read-only {@see MyDataConfigAudit}
 * the CLI and the go-live gate use: it checks each tenant's issuer identity,
 * invoice types and VAT categories against the AADE §8 code tables and reports
 * what AADE would reject (errors) or warn on, with the [nnn] business-error code
 * next to each. No network, no AADE call, no mutation. Read-only, super_admin.
 */
#[Name('preflight')]
#[Description('myDATA readiness audit (same as `php artisan mydata:preflight`), read-only, no AADE call — "are we ready to issue?". Per tenant: issuer-identity readiness, plus every invoice type / VAT category with a finding, each carrying its AADE [nnn] code. Returns error/warning counts and a clean flag; a non-zero error count is a go-live blocker. Optional `company` (slug); omit to audit every tenant. Read-only, super-admin.')]
#[IsReadOnly]
#[IsIdempotent]
class MyDataPreflightMcpTool extends ForensicMcpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'company' => $schema->string()
                ->description('Optional company slug to audit. Omit to audit every tenant you can reach.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $company = $request->get('company');
        $scope = $this->scope($request, is_string($company) ? $company : null);
        if ($scope['error'] !== null) {
            return self::json(['error' => $scope['error']]);
        }

        $audit = app(MyDataConfigAudit::class);

        $tenants = [];
        foreach ($scope['companies'] as $tenant) {
            $tenants[] = $this->auditOne($audit, $tenant);
        }

        $blocking = array_sum(array_map(static fn (array $t): int => $t['error_count'], $tenants));

        return self::json([
            'companies' => array_map(static fn (Company $c): string => (string) $c->slug, $scope['companies']),
            'ready' => $blocking === 0,
            'total_errors' => $blocking,
            'tenants' => $tenants,
            'note' => 'error_count > 0 = go-live blocker (η ΑΑΔΕ θα απέρριπτε). warnings = προς έλεγχο, δεν μπλοκάρουν.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditOne(MyDataConfigAudit $audit, Company $tenant): array
    {
        $result = $audit->audit($tenant);

        return [
            'company' => (string) $tenant->slug,
            'ready' => $result->errorCount() === 0,
            'error_count' => $result->errorCount(),
            'warn_count' => $result->warnCount(),
            'issuer' => $this->rowOut($result->tenant),
            // Only the rows that actually have something to fix — a clean tree
            // returns empty lists, not 20 "ok" rows.
            'invoice_types' => $this->problemRows($result->invoiceTypes),
            'vat_categories' => $this->problemRows($result->vatCategories),
        ];
    }

    /**
     * @param  list<ConfigAuditRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function problemRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row->status() !== 'ok') {
                $out[] = $this->rowOut($row);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOut(ConfigAuditRow $row): array
    {
        return [
            'label' => $row->label,
            'status' => $row->status(),
            'model_id' => $row->modelId,
            'messages' => $row->messages(),
        ];
    }
}
