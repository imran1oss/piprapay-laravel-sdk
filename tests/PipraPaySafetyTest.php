<?php

namespace Imran1oss\PipraPay\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Imran1oss\PipraPay\Exceptions\PipraPayRequestException;
use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;
use Imran1oss\PipraPay\PipraPayClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PipraPaySafetyTest extends TestCase
{
    private Factory $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new Factory();
        $this->http->preventStrayRequests();
    }

    public function test_checkout_is_not_retried_after_a_server_error(): void
    {
        $this->http->fakeSequence()
            ->push(['error' => ['message' => 'Temporary error']], 500)
            ->push(['pp_id' => 'PP-123', 'pp_url' => 'https://pay.example.com/payment/PP-123']);

        $this->expectException(PipraPayRequestException::class);

        try {
            $this->client()->createCheckout($this->checkoutPayload());
        } finally {
            $this->http->assertSentCount(1);
        }
    }

    public function test_checkout_is_not_retried_after_a_connection_failure(): void
    {
        $attempts = 0;
        $this->http->fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Simulated lost response');
        });

        $this->expectException(PipraPayRequestException::class);

        try {
            $this->client()->createCheckout($this->checkoutPayload());
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    public function test_verification_retries_transient_server_errors(): void
    {
        $this->http->fakeSequence()
            ->push([], 503)
            ->push($this->paymentPayload());

        $this->assertTrue($this->client()->verifyPayment('PP-123')->isCompleted());
        $this->http->assertSentCount(2);
    }

    public function test_verification_stops_at_the_configured_attempt_limit(): void
    {
        $this->http->fake(['*' => Factory::response([], 503)]);

        try {
            $this->client()->verifyPayment('PP-123');
            $this->fail('Expected a request exception.');
        } catch (PipraPayRequestException $exception) {
            $this->assertSame(503, $exception->statusCode);
        }

        $this->http->assertSentCount(3);
    }

    public function test_verification_wraps_exhausted_connection_failures(): void
    {
        $attempts = 0;
        $this->http->fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Simulated timeout');
        });

        try {
            $this->client()->verifyPayment('PP-123');
            $this->fail('Expected a request exception.');
        } catch (PipraPayRequestException $exception) {
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        }

        $this->assertSame(3, $attempts);
    }

    #[DataProvider('clientErrorStatuses')]
    public function test_verification_does_not_retry_client_errors(int $status): void
    {
        $this->http->fake(['*' => Factory::response([], $status)]);
        $this->expectException(PipraPayRequestException::class);

        try {
            $this->client()->verifyPayment('PP-123');
        } finally {
            $this->http->assertSentCount(1);
        }
    }

    public static function clientErrorStatuses(): array
    {
        return [[400], [401], [403], [422], [429]];
    }

    #[DataProvider('redirectStatuses')]
    public function test_api_redirects_do_not_forward_credentials(int $status): void
    {
        $history = [];
        $transport = new MockHandler([
            new Response($status, ['Location' => 'http://other.invalid/collect']),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->paymentPayload())),
        ]);
        $handler = Middleware::history($history)($transport);
        $this->http = new class($handler) extends Factory
        {
            public function __construct(private $transport)
            {
                parent::__construct();
            }

            protected function newPendingRequest()
            {
                return parent::newPendingRequest()->setHandler($this->transport);
            }
        };

        try {
            $this->client()->verifyPayment('PP-123');
            $this->fail('API redirects must be rejected.');
        } catch (PipraPayRequestException $exception) {
            $this->assertSame($status, $exception->statusCode);
        }

        $this->assertCount(1, $history);
        $this->assertSame('pay.example.com', $history[0]['request']->getUri()->getHost());
    }

    public static function redirectStatuses(): array
    {
        return [[301], [302], [303], [307], [308]];
    }

    public function test_verification_rejects_a_different_payment_reference(): void
    {
        $payload = $this->paymentPayload();
        $payload['pp_id'] = 'PP-OTHER';
        $this->http->fake(['*' => Factory::response($payload)]);

        $this->expectException(PipraPayResponseException::class);
        $this->client()->verifyPayment('PP-123');
    }

    public function test_webhook_verification_rejects_a_different_payment_reference(): void
    {
        $this->http->fake(['*' => Factory::response($this->paymentPayload())]);

        $this->expectException(PipraPayResponseException::class);
        $this->client()->verifyWebhook(['pp_id' => 'PP-OTHER', 'status' => 'completed']);
    }

    #[DataProvider('invalidAmounts')]
    public function test_checkout_rejects_non_finite_amounts_before_sending(mixed $amount): void
    {
        $this->http->fake();
        $payload = $this->checkoutPayload();
        $payload['amount'] = $amount;
        $this->expectException(PipraPayResponseException::class);

        try {
            $this->client()->createCheckout($payload);
        } finally {
            $this->http->assertNothingSent();
        }
    }

    public static function invalidAmounts(): array
    {
        return [[INF], [NAN], ['1e999'], [-INF], ['0'], ['-1'], [[]], [true]];
    }

    #[DataProvider('malformedPayments')]
    public function test_verification_rejects_malformed_payment_fields(array $changes): void
    {
        $this->http->fake(['*' => Factory::response(array_replace($this->paymentPayload(), $changes))]);
        $this->expectException(PipraPayResponseException::class);
        $this->client()->verifyPayment('PP-123');
    }

    public static function malformedPayments(): array
    {
        return [
            [['amount' => null]], [['currency' => null]],
            [['amount' => 'not-money']], [['amount' => '1e999']],
            [['amount' => '-1']], [['amount' => '0']],
            [['amount' => []]], [['currency' => []]], [['currency' => ' ']],
            [['status' => []]], [['pp_id' => []]], [['metadata' => 'invalid']],
            [['gateway' => []]], [['transaction_id' => []]],
        ];
    }

    public function test_verification_requires_amount_and_currency(): void
    {
        $this->http->fake(['*' => Factory::response(['pp_id' => 'PP-123', 'status' => 'completed'])]);
        $this->expectException(PipraPayResponseException::class);
        $this->client()->verifyPayment('PP-123');
    }

    public function test_verification_preserves_decimal_strings_and_normalizes_currency(): void
    {
        $payload = $this->paymentPayload();
        $payload['amount'] = '500.123456789012345678';
        $payload['currency'] = ' bdt ';
        $this->http->fake(['*' => Factory::response($payload)]);

        $payment = $this->client()->verifyPayment(' PP-123 ');
        $this->assertSame('500.123456789012345678', $payment->amount);
        $this->assertSame('BDT', $payment->currency);
    }

    #[DataProvider('malformedCheckoutFields')]
    public function test_checkout_rejects_malformed_fields_without_warnings(string $field, mixed $value): void
    {
        $this->http->fake();
        $payload = $this->checkoutPayload();
        $payload[$field] = $value;
        $this->expectException(PipraPayResponseException::class);

        try {
            $this->client()->createCheckout($payload);
        } finally {
            $this->http->assertNothingSent();
        }
    }

    public static function malformedCheckoutFields(): array
    {
        return [
            ['full_name', []], ['email_address', []], ['mobile_number', []],
            ['currency', []], ['return_url', []], ['webhook_url', []],
        ];
    }

    public function test_webhook_rejects_a_non_scalar_reference_without_http(): void
    {
        $this->http->fake();
        $this->expectException(PipraPayResponseException::class);

        try {
            $this->client()->verifyWebhook(['pp_id' => []]);
        } finally {
            $this->http->assertNothingSent();
        }
    }

    #[DataProvider('alteredReferences')]
    public function test_returned_payment_reference_is_compared_without_normalization(string $reference): void
    {
        $payload = $this->paymentPayload();
        $payload['pp_id'] = $reference;
        $this->http->fake(['*' => Factory::response($payload)]);
        $this->expectException(PipraPayResponseException::class);
        $this->client()->verifyPayment('PP-123');
    }

    public static function alteredReferences(): array
    {
        return [["PP-123\0"], [' PP-123'], ['PP-123 ']];
    }

    #[DataProvider('malformedApiErrors')]
    public function test_malformed_api_errors_still_produce_typed_request_exceptions(int $status, array $error): void
    {
        $this->http->fake(['*' => Factory::response(['error' => $error], $status)]);
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $this->client()->verifyPayment('PP-123');
            $this->fail('Expected a typed request exception.');
        } catch (PipraPayRequestException $exception) {
            $this->assertSame($status, $exception->statusCode);
            $this->assertNull($exception->errorCode);
        } finally {
            restore_error_handler();
        }
    }

    public static function malformedApiErrors(): array
    {
        return [
            [307, ['message' => ['invalid']]], [307, ['code' => ['invalid']]],
            [400, ['message' => ['invalid']]], [400, ['code' => ['invalid']]],
            [503, ['message' => ['invalid']]], [503, ['code' => ['invalid']]],
        ];
    }

    #[DataProvider('validCurrencyCodes')]
    public function test_custom_currency_codes_remain_supported(string $currency): void
    {
        $payload = $this->paymentPayload();
        $payload['currency'] = $currency;
        $this->http->fake(['*' => Factory::response($payload)]);
        $this->assertSame($currency, $this->client()->verifyPayment('PP-123')->currency);
    }

    public static function validCurrencyCodes(): array
    {
        return [['BDT'], ['USDT'], ['USD1'], ['ABCDEFGHIJ']];
    }

    #[DataProvider('invalidCurrencyCodes')]
    public function test_malformed_currency_codes_are_rejected(string $currency): void
    {
        $payload = $this->paymentPayload();
        $payload['currency'] = $currency;
        $this->http->fake(['*' => Factory::response($payload)]);
        $this->expectException(PipraPayResponseException::class);
        $this->client()->verifyPayment('PP-123');
    }

    public static function invalidCurrencyCodes(): array
    {
        return [['123!'], ['BN_D'], ['US'], ['ABCDEFGHIJK'], ["BDT\0"]];
    }

    public function test_pending_negative_amount_cannot_underflow_to_zero(): void
    {
        $payload = $this->paymentPayload();
        $payload['status'] = 'pending';
        $payload['amount'] = '-1e-1000';
        $this->http->fake(['*' => Factory::response($payload)]);
        $this->expectException(PipraPayResponseException::class);
        $this->client()->verifyPayment('PP-123');
    }

    private function client(): PipraPayClient
    {
        return new PipraPayClient(
            http: $this->http,
            baseUrl: 'https://pay.example.com',
            apiKey: 'test-api-key',
            attempts: 3,
            retrySleepMs: 0,
        );
    }

    private function checkoutPayload(): array
    {
        return [
            'full_name' => 'Demo User',
            'email_address' => 'demo@example.com',
            'mobile_number' => '01700000000',
            'amount' => '500.00',
            'currency' => 'BDT',
            'metadata' => ['deposit_id' => '1'],
        ];
    }

    private function paymentPayload(): array
    {
        return [
            'pp_id' => 'PP-123',
            'status' => 'completed',
            'amount' => '500.00',
            'currency' => 'BDT',
            'metadata' => ['deposit_id' => '1'],
        ];
    }
}
