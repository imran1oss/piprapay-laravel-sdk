<?php

namespace Imran1oss\PipraPay;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Illuminate\Http\Client\Response;
use Imran1oss\PipraPay\Contracts\PipraPayClientInterface;
use Imran1oss\PipraPay\Data\CheckoutResponse;
use Imran1oss\PipraPay\Data\PaymentResponse;
use Imran1oss\PipraPay\Exceptions\PipraPayConfigurationException;
use Imran1oss\PipraPay\Exceptions\PipraPayRequestException;
use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;
use Imran1oss\PipraPay\Support\PaymentValue;
use Imran1oss\PipraPay\Support\HttpUrl;
use JsonException;

class PipraPayClient implements PipraPayClientInterface
{
    /**
     * @param array{checkout: string, verify: string} $endpoints
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $currency = 'BDT',
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 5,
        private readonly int $attempts = 3,
        private readonly int $retrySleepMs = 200,
        private readonly array $endpoints = [
            'checkout' => '/api/checkout/redirect',
            'verify' => '/api/verify-payment',
        ],
    ) {
        $this->validateConfiguration();
    }

    public function createCheckout(array $payload): CheckoutResponse
    {
        $payload = $this->normalizeCheckoutPayload($payload);
        $response = $this->post($this->endpoint('checkout'), $payload);

        return CheckoutResponse::fromArray($response);
    }

    public function verifyPayment(string $paymentId): PaymentResponse
    {
        $paymentId = trim($paymentId);

        if ($paymentId === '') {
            throw new PipraPayResponseException('The PipraPay payment ID cannot be empty.');
        }

        $response = $this->post($this->endpoint('verify'), ['pp_id' => $paymentId], retryable: true);

        $payment = PaymentResponse::fromArray($response);

        if (! hash_equals($paymentId, (string) $response['pp_id'])) {
            throw new PipraPayResponseException('PipraPay returned a different payment ID.', $response);
        }

        return $payment;
    }

    public function verifyWebhook(array $payload): PaymentResponse
    {
        if (! is_string($payload['pp_id'] ?? null) && ! is_int($payload['pp_id'] ?? null)) {
            throw new PipraPayResponseException('The PipraPay webhook payload must contain a valid pp_id.', $payload);
        }

        $paymentId = trim((string) ($payload['pp_id'] ?? ''));

        if ($paymentId === '') {
            throw new PipraPayResponseException(
                'The PipraPay webhook payload is missing pp_id.',
                $payload,
            );
        }

        return $this->verifyPayment($paymentId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $payload, bool $retryable = false): array
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PipraPayResponseException('PipraPay request payload cannot be encoded as JSON.', $payload, $exception);
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->acceptJson()
                ->withBody($json, 'application/json')
                ->withoutRedirecting()
                ->withHeaders([
                    'MHS-PIPRAPAY-API-KEY' => $this->apiKey,
                    'User-Agent' => 'piprapay-laravel-sdk/1.0',
                ])
                ->connectTimeout(max(1, $this->connectTimeout))
                ->timeout(max(1, $this->timeout))
                ->retry(
                    $retryable ? max(1, $this->attempts) : 1,
                    max(0, $this->retrySleepMs),
                    static function (?Exception $exception): bool {
                        return $exception instanceof ConnectionException
                            || ($exception instanceof LaravelRequestException
                                && $exception->response->serverError());
                    },
                    throw: false,
                )
                ->post($endpoint);
        } catch (ConnectionException $exception) {
            throw new PipraPayRequestException(
                message: 'Unable to connect to PipraPay.',
                previous: $exception,
            );
        }

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        $decodeException = null;
        try {
            $body = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $decodeException = $exception;
            $body = [];
        }
        $body = is_array($body) && ! array_is_list($body) ? $body : [];

        if (! $response->successful() || isset($body['error'])) {
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];
            $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';

            throw new PipraPayRequestException(
                message: $message !== '' ? $message : 'PipraPay rejected the request.',
                statusCode: $response->status(),
                errorCode: self::nullableString($error['code'] ?? null),
                response: $body,
                previous: $decodeException,
            );
        }

        if ($body === []) {
            throw new PipraPayResponseException('PipraPay returned an empty or invalid JSON response.', previous: $decodeException);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeCheckoutPayload(array $payload): array
    {
        foreach (['full_name', 'email_address', 'mobile_number'] as $field) {
            if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                throw new PipraPayResponseException("The {$field} field is required.", $payload);
            }
        }

        if (filter_var((string) $payload['email_address'], FILTER_VALIDATE_EMAIL) === false) {
            throw new PipraPayResponseException('The email_address field must be valid.', $payload);
        }

        $amount = PaymentValue::amount($payload['amount'] ?? null);

        if ($amount === null) {
            throw new PipraPayResponseException('The amount must be a positive plain decimal number.', $payload);
        }

        foreach (['return_url', 'webhook_url'] as $field) {
            if (isset($payload[$field]) && ! is_string($payload[$field])) {
                throw new PipraPayResponseException("The {$field} field must be a valid URL.", $payload);
            }

            $url = trim((string) ($payload[$field] ?? ''));

            if ($url !== '' && ! HttpUrl::isValid($url)) {
                throw new PipraPayResponseException("The {$field} field must be a valid URL.", $payload);
            }
        }

        $metadata = $payload['metadata'] ?? [];

        if (! is_array($metadata)) {
            throw new PipraPayResponseException('The metadata field must be an array.', $payload);
        }

        $currency = PaymentValue::currency($payload['currency'] ?? $this->currency);

        if ($currency === null) {
            throw new PipraPayResponseException('The currency field must contain 3–10 letters or digits.', $payload);
        }

        return [
            'full_name' => trim((string) $payload['full_name']),
            'email_address' => trim((string) $payload['email_address']),
            'mobile_number' => trim((string) $payload['mobile_number']),
            'amount' => (string) $amount,
            'currency' => $currency,
            'return_url' => trim((string) ($payload['return_url'] ?? '')),
            'webhook_url' => trim((string) ($payload['webhook_url'] ?? '')),
            'metadata' => $metadata,
        ];
    }

    private function endpoint(string $name): string
    {
        $endpoint = trim((string) ($this->endpoints[$name] ?? ''));

        if ($endpoint === '') {
            throw new PipraPayConfigurationException("The PipraPay {$name} endpoint is not configured.");
        }

        return '/'.ltrim($endpoint, '/');
    }

    private function validateConfiguration(): void
    {
        if (! HttpUrl::isValid($this->baseUrl)
            || parse_url($this->baseUrl, PHP_URL_QUERY) !== null
            || parse_url($this->baseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new PipraPayConfigurationException('PIPRAPAY_BASE_URL must be an HTTP(S) URL without a query or fragment.');
        }

        if (trim($this->apiKey) === '') {
            throw new PipraPayConfigurationException('PIPRAPAY_API_KEY is not configured.');
        }

        if (PaymentValue::currency($this->currency) === null) {
            throw new PipraPayConfigurationException('PIPRAPAY_CURRENCY must contain 3–10 letters or digits.');
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
