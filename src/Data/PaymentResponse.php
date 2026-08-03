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
        $paymentId = trim((string) ($payload['pp_id'] ?? ''));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));

        if ($paymentId === '' || $status === '') {
            throw new PipraPayResponseException(
                'PipraPay verification response is missing pp_id or status.',
                $payload,
            );
        }

        $metadata = $payload['metadata'] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];

        return new self(
            paymentId: $paymentId,
            status: $status,
            amount: (string) ($payload['amount'] ?? '0'),
            currency: strtoupper((string) ($payload['currency'] ?? '')),
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
