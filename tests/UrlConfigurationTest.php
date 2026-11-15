<?php

namespace Imran1oss\PipraPay\Tests;

use Illuminate\Http\Client\Factory;
use Imran1oss\PipraPay\Data\CheckoutResponse;
use Imran1oss\PipraPay\Exceptions\PipraPayConfigurationException;
use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;
use Imran1oss\PipraPay\PipraPayClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlConfigurationTest extends TestCase
{
    #[DataProvider('invalidBaseUrls')]
    public function test_invalid_base_urls_fail_before_http(string $url): void
    {
        $http = new Factory();
        $http->fake();
        $this->expectException(PipraPayConfigurationException::class);
        try {
            new PipraPayClient($http, $url, 'test-key');
        } finally {
            $http->assertNothingSent();
        }
    }

    public static function invalidBaseUrls(): array
    {
        return [['ftp://pay.example.com'], ['https://pay.example.com?tenant=1'],
            ['https://pay.example.com#fragment'], ['https://pay.example.com?'], ['https://pay.example.com#']];
    }

    #[DataProvider('validBaseUrls')]
    public function test_subdirectory_and_local_http_base_urls_work(string $base, string $expected): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake([$expected => Factory::response(['pp_id' => 'PP-123', 'pp_url' => 'https://pay.example.com/payment/PP-123'])]);
        (new PipraPayClient($http, $base, 'test-key'))->createCheckout($this->payload());
        $http->assertSent(fn ($request): bool => $request->url() === $expected);
    }

    public static function validBaseUrls(): array
    {
        return [['https://pay.example.com/panel/', 'https://pay.example.com/panel/api/checkout/redirect'],
            ['http://127.0.0.1:8080', 'http://127.0.0.1:8080/api/checkout/redirect']];
    }

    #[DataProvider('invalidCallbacks')]
    public function test_callbacks_require_http_or_https(string $field, string $url): void
    {
        $http = new Factory();
        $http->fake();
        $payload = $this->payload();
        $payload[$field] = $url;
        $this->expectException(PipraPayResponseException::class);
        try {
            (new PipraPayClient($http, 'https://pay.example.com', 'test-key'))->createCheckout($payload);
        } finally {
            $http->assertNothingSent();
        }
    }

    public static function invalidCallbacks(): array
    {
        return [['return_url', 'ftp://broker.example.com/return'], ['webhook_url', 'ftp://broker.example.com/notify']];
    }

    #[DataProvider('invalidDirectCheckouts')]
    public function test_direct_construction_enforces_checkout_invariants(string $id, string $url): void
    {
        $this->expectException(PipraPayResponseException::class);
        (new CheckoutResponse($id, $url))->directUrl('BKASH');
    }

    public static function invalidDirectCheckouts(): array
    {
        return [['PP-123', 'javascript:alert(1)'], ['PP-123', 'ftp://pay.example.com/payment/PP-123'],
            ['', 'https://pay.example.com/payment/PP-123'], ["PP-123\0", 'https://pay.example.com/payment/PP-123']];
    }

    public function test_factory_does_not_silently_change_remote_payment_ids(): void
    {
        $this->expectException(PipraPayResponseException::class);
        CheckoutResponse::fromArray(['pp_id' => "PP-123\0", 'pp_url' => 'https://pay.example.com/payment/PP-123']);
    }

    public function test_empty_optional_callbacks_remain_supported(): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake(['*' => Factory::response(['pp_id' => 'PP-123', 'pp_url' => 'https://pay.example.com/payment/PP-123'])]);
        $payload = $this->payload();
        $payload['return_url'] = '';
        $payload['webhook_url'] = '';
        $this->assertSame('PP-123', (new PipraPayClient($http, 'https://pay.example.com', 'test-key'))->createCheckout($payload)->paymentId);
    }

    private function payload(): array
    {
        return ['full_name' => 'Demo User', 'email_address' => 'demo@example.com', 'mobile_number' => '01700000000', 'amount' => '500.00'];
    }
}
