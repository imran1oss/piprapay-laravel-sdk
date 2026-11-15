<?php

namespace Imran1oss\PipraPay\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Imran1oss\PipraPay\Exceptions\PipraPayRequestException;
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
