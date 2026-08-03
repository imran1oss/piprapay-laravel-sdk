<?php

namespace Imran1oss\PipraPay\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Imran1oss\PipraPay\Contracts\PipraPayClientInterface;
use Imran1oss\PipraPay\Exceptions\PipraPayRequestException;
use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;

class PipraPayClientTest extends TestCase
{
    public function test_it_creates_a_checkout_and_builds_a_direct_gateway_url(): void
    {
        Http::fake([
            'https://pay.example.com/api/checkout/redirect' => Http::response([
                'pp_id' => 'PP-123',
                'pp_url' => 'https://pay.example.com/payment/PP-123',
            ]),
        ]);

        $checkout = $this->client()->createCheckout([
            'full_name' => 'Demo User',
            'email_address' => 'demo@example.com',
            'mobile_number' => '01700000000',
            'amount' => '500.00',
            'return_url' => 'https://broker.example.com/payment/return',
            'webhook_url' => 'https://broker.example.com/webhooks/piprapay',
            'metadata' => ['deposit_trx' => 'ABC123'],
        ]);

        $this->assertSame('PP-123', $checkout->paymentId);
        $this->assertSame(
            'https://pay.example.com/payment/PP-123?gateway=GW-BKASH',
            $checkout->directUrl('GW-BKASH'),
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://pay.example.com/api/checkout/redirect'
                && $request->method() === 'POST'
                && $request->hasHeader('MHS-PIPRAPAY-API-KEY', 'test-api-key')
                && $request['currency'] === 'BDT'
                && $request['metadata']['deposit_trx'] === 'ABC123';
        });
    }

    public function test_it_verifies_a_payment(): void
    {
        Http::fake([
            'https://pay.example.com/api/verify-payment' => Http::response([
                'pp_id' => 'PP-123',
                'status' => 'completed',
                'amount' => '500.00',
                'currency' => 'BDT',
                'gateway' => 'bKash',
                'transaction_id' => 'TRX-999',
                'metadata' => ['deposit_trx' => 'ABC123'],
            ]),
        ]);

        $payment = $this->client()->verifyPayment('PP-123');

        $this->assertTrue($payment->isCompleted());
        $this->assertSame('500.00', $payment->amount);
        $this->assertSame('TRX-999', $payment->transactionId);
    }

    public function test_webhook_payload_is_verified_against_the_api(): void
    {
        Http::fake([
            'https://pay.example.com/api/verify-payment' => Http::response([
                'pp_id' => 'PP-123',
                'status' => 'pending',
                'amount' => '500.00',
                'currency' => 'BDT',
            ]),
        ]);

        $payment = $this->client()->verifyWebhook([
            'pp_id' => 'PP-123',
            'status' => 'completed',
        ]);

        $this->assertTrue($payment->isPending());

        Http::assertSentCount(1);
    }

    public function test_it_throws_a_typed_exception_for_api_errors(): void
    {
        Http::fake([
            'https://pay.example.com/api/verify-payment' => Http::response([
                'error' => [
                    'code' => 'INVALID_PP_ID',
                    'message' => 'A valid pp id is required.',
                ],
            ], 400),
        ]);

        try {
            $this->client()->verifyPayment('invalid');
            $this->fail('Expected PipraPayRequestException was not thrown.');
        } catch (PipraPayRequestException $exception) {
            $this->assertSame(400, $exception->statusCode);
            $this->assertSame('INVALID_PP_ID', $exception->errorCode);
        }
    }

    public function test_it_rejects_an_invalid_checkout_payload_before_calling_the_api(): void
    {
        Http::fake();

        $this->expectException(PipraPayResponseException::class);

        try {
            $this->client()->createCheckout([
                'full_name' => 'Demo User',
                'email_address' => 'not-an-email',
                'mobile_number' => '01700000000',
                'amount' => '500.00',
            ]);
        } finally {
            Http::assertNothingSent();
        }
    }

    private function client(): PipraPayClientInterface
    {
        return $this->app->make(PipraPayClientInterface::class);
    }
}
