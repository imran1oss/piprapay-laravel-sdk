<?php

namespace Imran1oss\PipraPay\Tests;

use Illuminate\Http\Client\Factory;
use Imran1oss\PipraPay\PipraPayClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderFixturesTest extends TestCase
{
    #[DataProvider('paymentStatuses')]
    public function test_provider_verification_fixtures_preserve_the_contract(string $status, string $helper): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake(['https://pay.example.com/api/verify-payment' => Factory::response($this->fixture('payment-'.$status))]);
        $id = '349452200706799329851862826';
        $payment = (new PipraPayClient($http, 'https://pay.example.com', 'test-key'))->verifyPayment($id);
        $this->assertTrue($payment->$helper());
        $this->assertSame($id, $payment->paymentId);
        $this->assertSame('500.00', $payment->amount);
        $this->assertSame('BDT', $payment->currency);
        $this->assertSame('42', $payment->metadata['deposit_id']);
        $this->assertSame('SDK-TRX-42', $payment->transactionId);
        $this->assertSame($status, $payment->toArray()['status']);
        $http->assertSent(fn ($request): bool => $request->method() === 'POST' && $request['pp_id'] === $id);
    }

    public static function paymentStatuses(): array
    {
        return [['initiated', 'isPending'], ['pending', 'isPending'], ['completed', 'isCompleted'],
            ['canceled', 'isCanceled'], ['refunded', 'isRefunded']];
    }

    public function test_full_webhook_fixture_cannot_override_api_status(): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake(['https://pay.example.com/api/verify-payment' => Factory::response($this->fixture('payment-pending'))]);
        $payload = json_decode($this->fixture('webhook'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('completed', $payload['status']);
        $payment = (new PipraPayClient($http, 'https://pay.example.com', 'test-key'))->verifyWebhook($payload);
        $this->assertTrue($payment->isPending());
        $http->assertSentCount(1);
    }

    public function test_checkout_fixture_supports_direct_gateway_redirects(): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake(['https://pay.example.com/api/checkout/redirect' => Factory::response($this->fixture('checkout'))]);
        $checkout = (new PipraPayClient($http, 'https://pay.example.com', 'test-key'))->createCheckout([
            'full_name' => 'SDK Fixture User', 'email_address' => 'fixture@example.invalid',
            'mobile_number' => '01700000000', 'amount' => '500.00', 'metadata' => ['deposit_id' => '42'],
        ]);
        $this->assertSame('https://pay.example.com/payment/349452200706799329851862826?gateway=GW-BKASH', $checkout->directUrl('GW-BKASH'));
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/Fixtures/'.$name.'.json');
    }
}
