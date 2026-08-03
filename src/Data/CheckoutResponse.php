<?php

namespace Imran1oss\PipraPay\Data;

use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;

final readonly class CheckoutResponse
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $paymentId,
        public string $checkoutUrl,
        public array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $paymentId = trim((string) ($payload['pp_id'] ?? ''));
        $checkoutUrl = trim((string) ($payload['pp_url'] ?? ''));

        if ($paymentId === '' || $checkoutUrl === '') {
            throw new PipraPayResponseException(
                'PipraPay checkout response is missing pp_id or pp_url.',
                $payload,
            );
        }

        if (filter_var($checkoutUrl, FILTER_VALIDATE_URL) === false) {
            throw new PipraPayResponseException(
                'PipraPay returned an invalid checkout URL.',
                $payload,
            );
        }

        return new self($paymentId, $checkoutUrl, $payload);
    }

    public function directUrl(string $externalGatewayCode): string
    {
        $externalGatewayCode = trim($externalGatewayCode);

        if ($externalGatewayCode === '') {
            throw new PipraPayResponseException(
                'The external gateway code cannot be empty.',
                $this->raw,
            );
        }

        $separator = str_contains($this->checkoutUrl, '?') ? '&' : '?';

        return $this->checkoutUrl.$separator.http_build_query(
            ['gateway' => $externalGatewayCode],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /**
     * @return array{pp_id: string, pp_url: string}
     */
    public function toArray(): array
    {
        return [
            'pp_id' => $this->paymentId,
            'pp_url' => $this->checkoutUrl,
        ];
    }
}
