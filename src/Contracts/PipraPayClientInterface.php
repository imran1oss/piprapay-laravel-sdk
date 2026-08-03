<?php

namespace Imran1oss\PipraPay\Contracts;

use Imran1oss\PipraPay\Data\CheckoutResponse;
use Imran1oss\PipraPay\Data\PaymentResponse;

interface PipraPayClientInterface
{
    /**
     * @param array{
     *     full_name: string,
     *     email_address: string,
     *     mobile_number: string,
     *     amount: int|float|string,
     *     currency?: string,
     *     return_url?: string,
     *     webhook_url?: string,
     *     metadata?: array<string, mixed>
     * } $payload
     */
    public function createCheckout(array $payload): CheckoutResponse;

    public function verifyPayment(string $paymentId): PaymentResponse;

    /**
     * PipraPay webhooks are verified against the API before a result is returned.
     *
     * @param array<string, mixed> $payload
     */
    public function verifyWebhook(array $payload): PaymentResponse;
}
