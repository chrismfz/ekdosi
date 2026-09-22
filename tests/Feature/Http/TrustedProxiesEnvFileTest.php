<?php

namespace Tests\Feature\Http;

use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The regression behind this middleware: TRUSTED_PROXIES was read with env()
 * inside bootstrap/app.php's withMiddleware(), which runs when the HTTP kernel
 * is resolved — BEFORE the .env file is loaded. A value that lived only in .env
 * (the normal way to configure a deploy) was silently ignored. This boots a
 * separate app from a real .env file and proves the value now takes effect.
 */
class TrustedProxiesEnvFileTest extends TestCase
{
    public function test_a_trusted_proxy_set_only_in_the_env_file_takes_effect(): void
    {
        if (getenv('TRUSTED_PROXIES') !== false || $this->app->configurationIsCached()) {
            $this->markTestSkipped('needs an uncached config and no TRUSTED_PROXIES in the process env');
        }

        $dir = sys_get_temp_dir().'/ekdosi-envfile-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/.env', "TRUSTED_PROXIES=198.51.100.5\n");

        try {
            $app = require base_path('bootstrap/app.php');
            $app->useEnvironmentPath($dir);
            $app->loadEnvironmentFrom('.env');

            $kernel = $app->make(HttpKernel::class);
            $kernel->bootstrap();
            Route::get('/__envfile-probe', fn (Request $request) => $request->ip());

            $request = Request::create('/__envfile-probe', 'GET', server: [
                'REMOTE_ADDR' => '198.51.100.5',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            ]);
            $response = $kernel->handle($request);

            $this->assertSame('203.0.113.9', $response->getContent());
        } finally {
            // Dotenv wrote into the process env; the second app took over the facades.
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
            @unlink($dir.'/.env');
            @rmdir($dir);
            // …and Symfony's static proxy list still names the probe's proxy.
            Request::setTrustedProxies([], -1);
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }
    }
}
