<?php

namespace Imran1oss\PipraPay\Data;

use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;

final readonly class PaymentResponse
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $paymentId,
        public string $status,
        public string $amount,
        public string $currency,
        public ?string $gateway,
        public ?string $transactionId,
        public array $metadata,
        public array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if ((! is_string($payload['pp_id'] ?? null) && ! is_int($payload['pp_id'] ?? null))
            || ! is_string($payload['status'] ?? null)) {
            throw new PipraPayResponseException('PipraPay verification response has an invalid pp_id or status.', $payload);
        }

        $paymentId = trim((string) ($payload['pp_id'] ?? ''));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));

        if ($paymentId === '' || $status === '') {
            throw new PipraPayResponseException(
                'PipraPay verification response is missing pp_id or status.',
                $payload,
            );
        }

        $amount = $payload['amount'] ?? null;

        if (! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount < 0
            || ($status === 'completed' && (float) $amount <= 0)) {
            throw new PipraPayResponseException('PipraPay verification response has an invalid amount.', $payload);
        }

        if (! is_string($payload['currency'] ?? null) || trim($payload['currency']) === '') {
            throw new PipraPayResponseException('PipraPay verification response is missing a valid currency.', $payload);
        }

        $metadata = $payload['metadata'] ?? [];

        if (! is_array($metadata)) {
            throw new PipraPayResponseException('PipraPay verification response has invalid metadata.', $payload);
        }

        foreach (['gateway', 'transaction_id'] as $field) {
            if (isset($payload[$field]) && ! is_string($payload[$field]) && ! is_int($payload[$field])) {
                throw new PipraPayResponseException("PipraPay verification response has an invalid {$field}.", $payload);
            }
        }

        return new self(
            paymentId: $paymentId,
            status: $status,
            amount: (string) $amount,
            currency: strtoupper(trim($payload['currency'])),
            gateway: self::nullableString($payload['gateway'] ?? null),
            transactionId: self::nullableString($payload['transaction_id'] ?? null),
            metadata: $metadata,
            raw: $payload,
        );
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['initiated', 'pending'], true);
    }

    public function isCanceled(): bool
    {
        return in_array($this->status, ['canceled', 'cancelled'], true);
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || $value === '--' ? null : $value;
    }
}
