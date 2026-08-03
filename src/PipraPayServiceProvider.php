<?php

namespace Imran1oss\PipraPay;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Imran1oss\PipraPay\Contracts\PipraPayClientInterface;

class PipraPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/piprapay.php', 'piprapay');

        $this->app->singleton(PipraPayClientInterface::class, function ($app): PipraPayClient {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('piprapay', []);

            return new PipraPayClient(
                http: $app->make(Factory::class),
                baseUrl: (string) ($config['base_url'] ?? ''),
                apiKey: (string) ($config['api_key'] ?? ''),
                currency: (string) ($config['currency'] ?? 'BDT'),
                timeout: (int) ($config['timeout'] ?? 15),
                connectTimeout: (int) ($config['connect_timeout'] ?? 5),
                attempts: (int) ($config['attempts'] ?? 3),
                retrySleepMs: (int) ($config['retry_sleep_ms'] ?? 200),
                endpoints: is_array($config['endpoints'] ?? null)
                    ? $config['endpoints']
                    : [],
            );
        });

        $this->app->alias(PipraPayClientInterface::class, PipraPayClient::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/piprapay.php' => config_path('piprapay.php'),
        ], 'piprapay-config');
    }
}
