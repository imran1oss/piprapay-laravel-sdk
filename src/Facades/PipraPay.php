<?php

namespace Imran1oss\PipraPay\Facades;

use Illuminate\Support\Facades\Facade;
use Imran1oss\PipraPay\Contracts\PipraPayClientInterface;

/**
 * @method static \Imran1oss\PipraPay\Data\CheckoutResponse createCheckout(array $payload)
 * @method static \Imran1oss\PipraPay\Data\PaymentResponse verifyPayment(string $paymentId)
 * @method static \Imran1oss\PipraPay\Data\PaymentResponse verifyWebhook(array $payload)
 *
 * @see \Imran1oss\PipraPay\PipraPayClient
 */
class PipraPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PipraPayClientInterface::class;
    }
}
