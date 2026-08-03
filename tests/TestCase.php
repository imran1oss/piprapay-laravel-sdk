<?php

namespace Imran1oss\PipraPay\Tests;

use Imran1oss\PipraPay\PipraPayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PipraPayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('piprapay', [
            'base_url' => 'https://pay.example.com',
            'api_key' => 'test-api-key',
            'currency' => 'BDT',
            'timeout' => 15,
            'connect_timeout' => 5,
            'attempts' => 1,
            'retry_sleep_ms' => 0,
            'endpoints' => [
                'checkout' => '/api/checkout/redirect',
                'verify' => '/api/verify-payment',
            ],
        ]);
    }
}
