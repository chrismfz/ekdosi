<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Mint (or revoke) a Sanctum personal-access token for the ekdosi MCP server
 * (routes/ai.php, auth:sanctum). The token is BOUND TO A TENANT: it carries a
 * `tenant:{id}` ability that McpTenantResolver reads back, so an external MCP
 * caller acts as exactly one company — the company is never a model-supplied
 * argument. A user with several companies mints one token per company.
 *
 * The plaintext token is printed ONCE (only recoverable at creation). Give it to
 * the MCP client as the `Authorization: Bearer <token>` value. This authenticates
 * the ekdosi USER; per-tool access is still gated by that user's Shield
 * permissions inside every tool (same as the panel).
 */
class McpToken extends Command
{
    protected $signature = 'ekdosi:mcp-token
        {email : The email of the user the token authenticates as}
        {--tenant= : Company slug (or id) the token is bound to; optional if the user has exactly one}
        {--name= : A label for the token (default: mcp:<slug>)}
        {--revoke : Revoke tokens with the given --name for this user instead of minting}';

    protected $description = 'Mint (or revoke) a tenant-bound Sanctum bearer token for the ekdosi MCP server';

    public function handle(): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with email '.$this->argument('email').'.');

            return self::FAILURE;
        }

        $company = $this->resolveCompany($user);
        if ($company === null) {
            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: 'mcp:'.$company->slug);

        if ($this->option('revoke')) {
            $deleted = $user->tokens()->where('name', $name)->delete();
            $this->info("Revoked {$deleted} token(s) named '{$name}' for {$user->email}.");

            return self::SUCCESS;
        }

        // The tenant binding is the token's ability. Tools do NOT ability-gate on
        // it (permissions are Shield's job); it only carries which company.
        $plain = $user->createToken($name, ['tenant:'.$company->getKey()])->plainTextToken;

        $this->newLine();
        $this->info("MCP bearer token for {$user->email} · company «{$company->name}» ({$company->slug}) · label '{$name}'.");
        $this->comment('Store it now — it is shown only once:');
        $this->line($plain);
        $this->newLine();
        $this->comment('Configure your MCP client with header:  Authorization: Bearer '.$plain);

        return self::SUCCESS;
    }

    private function resolveCompany(User $user): ?Company
    {
        $arg = $this->option('tenant');

        if ($arg !== null && $arg !== '') {
            $company = Company::findBySlugOrId((string) $arg);
            if ($company === null) {
                $this->error("No company matching --tenant='{$arg}'.");

                return null;
            }
            if (! $user->canAccessTenant($company)) {
                $this->error("{$user->email} does not belong to company «{$company->name}».");

                return null;
            }

            return $company;
        }

        // No --tenant: only unambiguous when the user has exactly one company.
        $companies = $user->companies()->limit(2)->get();
        if ($companies->count() === 1) {
            return $companies->first();
        }

        if ($companies->isEmpty()) {
            $this->error("{$user->email} belongs to no company — attach them to one first.");
        } else {
            $this->error('User belongs to several companies — pass --tenant=<slug> to bind the token.');
        }

        return null;
    }
}
