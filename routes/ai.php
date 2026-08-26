<?php

use App\Mcp\Servers\EkdosiMcpServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Passport;

/*
|--------------------------------------------------------------------------
| AI / MCP Routes
|--------------------------------------------------------------------------
| The ekdosi MCP server, mounted at `/mcp`. It exposes the SAME tenant-safe tool
| registry as the in-app «Βοηθός», so ekdosi is drivable from outside the panel
| through one MCP connection (Claude Desktop, the claude.ai connector, another
| agent). See MCP.md.
|
| Laravel MCP auto-loads this file (routes/ai.php) via its service provider, so
| bootstrap/app.php needs no change. Everything is guarded by class_exists so the
| file is inert if the packages aren't installed yet — the app boots fine before
| `composer require laravel/mcp laravel/sanctum`.
|
| Universal auth — the endpoint accepts EITHER credential:
|   - Sanctum bearer  (desktop / CLI / curl — you paste the token). Always on.
|     Mint with `php artisan ekdosi:mcp-token <email> --tenant=<slug>`; the token
|     carries the company binding (McpTenantResolver reads it back). NEVER trust a
|     company named by the model.
|   - OAuth 2.1       (the claude.ai remote connector, which ONLY speaks OAuth +
|     Dynamic Client Registration). Active once Laravel Passport is installed.
|
| Passport is optional and class_exists-gated: until it is installed the endpoint
| runs Sanctum-only; once it is, OAuth discovery/DCR routes are registered and the
| endpoint additionally accepts OAuth access tokens. See MCP.md §6.
|
| Always-on: there is no enable flag. The endpoint is protected by auth (a request
| without a valid Sanctum/OAuth token is rejected), and by class_exists (it can't
| mount without laravel/mcp installed) — so a config toggle adds nothing but a
| foot-gun. The in-app «Βοηθός» is unaffected either way; it doesn't use this route.
*/

if (! class_exists(Mcp::class)) {
    // laravel/mcp not installed — nothing to mount. (Belt-and-braces; the
    // package provider is what loads this file, so this is effectively never hit.)
    return;
}

$passportReady = class_exists(Passport::class);

if ($passportReady) {
    // Registers the OAuth2 discovery + Dynamic Client Registration routes the
    // claude.ai connector negotiates.
    Mcp::oauthRoutes();
}

// auth:api = Passport (OAuth access token); auth:sanctum = personal-access token.
// Listing both lets one endpoint authenticate either — the first guard that
// accepts the presented bearer wins.
$guards = $passportReady ? 'auth:api,sanctum' : 'auth:sanctum';

Mcp::web('/mcp', EkdosiMcpServer::class)
    ->middleware($guards);
