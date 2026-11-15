<?php

namespace Imran1oss\PipraPay\Data;

use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;
use Imran1oss\PipraPay\Support\HttpUrl;

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
        if ($paymentId === '' || preg_match('/[\x00-\x20\x7f]/', $paymentId) === 1) {
            throw new PipraPayResponseException('PipraPay checkout response has an invalid pp_id.', $raw);
        }

        if (! HttpUrl::isValid($checkoutUrl)) {
            throw new PipraPayResponseException('PipraPay returned an invalid checkout URL.', $raw);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if ((! is_string($payload['pp_id'] ?? null) && ! is_int($payload['pp_id'] ?? null))
            || ! is_string($payload['pp_url'] ?? null)) {
            throw new PipraPayResponseException('PipraPay checkout response has an invalid pp_id or pp_url.', $payload);
        }

        $paymentId = (string) $payload['pp_id'];
        $checkoutUrl = trim((string) ($payload['pp_url'] ?? ''));

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

        $fragmentParts = explode('#', $this->checkoutUrl, 2);
        $urlParts = explode('?', $fragmentParts[0], 2);
        $query = array_filter(
            explode('&', $urlParts[1] ?? ''),
            static fn (string $parameter): bool => $parameter !== ''
                && urldecode(explode('=', $parameter, 2)[0]) !== 'gateway',
        );

        $query[] = http_build_query(
            ['gateway' => $externalGatewayCode],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return $urlParts[0].'?'.implode('&', $query)
            .(isset($fragmentParts[1]) ? '#'.$fragmentParts[1] : '');
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
