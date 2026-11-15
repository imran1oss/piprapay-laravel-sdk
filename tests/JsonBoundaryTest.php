<?php

namespace Imran1oss\PipraPay\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Imran1oss\PipraPay\Exceptions\PipraPayRequestException;
use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;
use Imran1oss\PipraPay\PipraPayClient;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsonBoundaryTest extends TestCase
{
    #[DataProvider('invalidMetadata')]
    public function test_invalid_metadata_raises_a_typed_exception_before_http(array $metadata): void
    {
        $http = new Factory();
        $http->fake();
        try {
            $this->client($http)->createCheckout($this->payload($metadata));
            $this->fail('Invalid JSON values must be rejected.');
        } catch (PipraPayResponseException $exception) {
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
        } finally {
            $http->assertNothingSent();
        }
    }

    public static function invalidMetadata(): array
    {
        return [[['value' => NAN]], [['value' => INF]], [['value' => "\xB1\x31"]]];
    }

    public function test_recursive_metadata_is_rejected_before_http(): void
    {
        $metadata = [];
        $metadata['recursive'] = &$metadata;
        $http = new Factory();
        $http->fake();
        $this->expectException(PipraPayResponseException::class);
        try {
            $this->client($http)->createCheckout($this->payload($metadata));
        } finally {
            $http->assertNothingSent();
        }
    }

    public function test_resource_metadata_is_rejected_before_http(): void
    {
        $resource = fopen('php://memory', 'r+');
        $http = new Factory();
        $http->fake();
        $this->expectException(PipraPayResponseException::class);
        try {
            $this->client($http)->createCheckout($this->payload(['value' => $resource]));
        } finally {
            fclose($resource);
            $http->assertNothingSent();
        }
    }

    #[DataProvider('serializationFailures')]
    public function test_serializer_callbacks_cannot_escape_the_sdk_boundary(\Throwable $failure): void
    {
        $value = new class($failure) implements \JsonSerializable
        {
            public function __construct(private \Throwable $failure) {}
            public function jsonSerialize(): mixed { throw $this->failure; }
        };
        $http = new Factory();
        $http->fake();
        try {
            $this->client($http)->createCheckout($this->payload(['value' => $value]));
            $this->fail('Expected a typed encoding failure.');
        } catch (PipraPayResponseException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        } finally {
            $http->assertNothingSent();
        }
    }

    public static function serializationFailures(): array
    {
        return [[new \RuntimeException('Serializer callback failed')], [new \Error('Serializer hook failed')]];
    }

    public function test_metadata_is_encoded_once_and_sent_as_the_validated_json(): void
    {
        $value = new class implements \JsonSerializable
        {
            public int $calls = 0;
            public function jsonSerialize(): mixed
            {
                $this->calls++;
                return $this->calls === 1 ? 'invoice-1' : NAN;
            }
        };
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake(['*' => Factory::response(['pp_id' => 'PP-123', 'pp_url' => 'https://pay.example.com/payment/PP-123'])]);
        $this->client($http)->createCheckout($this->payload(['value' => $value]));
        $this->assertSame(1, $value->calls);
        $http->assertSent(fn ($request): bool => $request['metadata']['value'] === 'invoice-1');
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_response_json_has_a_typed_exception_regardless_of_global_flags(string $body, int $flags): void
    {
        $supportsFlags = property_exists(Response::class, 'defaultJsonDecodingFlags');
        $old = $supportsFlags ? Response::$defaultJsonDecodingFlags : null;
        if ($supportsFlags) {
            Response::$defaultJsonDecodingFlags = $flags;
        }
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake(['*' => Factory::response($body, 200)]);
        $this->expectException(PipraPayResponseException::class);
        try {
            $this->client($http)->verifyPayment('PP-123');
        } finally {
            if ($supportsFlags) {
                Response::$defaultJsonDecodingFlags = $old;
            }
        }
    }

    public static function invalidResponses(): array
    {
        return [['<html>invalid</html>', 0], ['<html>invalid</html>', JSON_THROW_ON_ERROR],
            ['', 0], ['[]', 0], ['"scalar"', 0], ["{\"invalid\":\"\xB1\"}", 0]];
    }

    public function test_invalid_json_preserves_the_decode_cause(): void
    {
        $http = new Factory();
        $http->fake(['*' => Factory::response('{invalid', 200)]);
        try {
            $this->client($http)->verifyPayment('PP-123');
            $this->fail('Expected a response exception.');
        } catch (PipraPayResponseException $exception) {
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }

    public function test_http_error_with_invalid_json_retains_status_and_cause(): void
    {
        $http = new Factory();
        $http->fake(['*' => Factory::response('upstream unavailable', 503)]);
        try {
            $this->client($http)->verifyPayment('PP-123');
            $this->fail('Expected a request exception.');
        } catch (PipraPayRequestException $exception) {
            $this->assertSame(503, $exception->statusCode);
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }

    #[DataProvider('largeIdBodies')]
    public function test_large_ids_preserve_exact_identity(string $idJson): void
    {
        $id = '349452200706799329851862826';
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake(['*' => Factory::response('{"pp_id":'.$idJson.',"status":"completed","amount":"500.00","currency":"BDT"}', 200)]);
        $this->assertSame($id, $this->client($http)->verifyPayment($id)->paymentId);
    }

    public static function largeIdBodies(): array
    {
        return [['349452200706799329851862826'], ['"349452200706799329851862826"']];
    }

    private function client(Factory $http): PipraPayClient
    {
        return new PipraPayClient($http, 'https://pay.example.com', 'test-key', attempts: 1);
    }

    private function payload(array $metadata): array
    {
        return ['full_name' => 'Demo User', 'email_address' => 'demo@example.com', 'mobile_number' => '01700000000', 'amount' => '500.00', 'metadata' => $metadata];
    }
}
