<?php

namespace Imran1oss\PipraPay\Tests;

use Illuminate\Http\Client\Factory;
use Imran1oss\PipraPay\Data\PaymentResponse;
use Imran1oss\PipraPay\Exceptions\PipraPayConfigurationException;
use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;
use Imran1oss\PipraPay\PipraPayClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentValuesTest extends TestCase
{
    #[DataProvider('invalidCurrencies')]
    public function test_checkout_rejects_invalid_currency_before_http(string $currency): void
    {
        $http = new Factory();
        $http->fake();
        $this->expectException(PipraPayResponseException::class);
        try {
            (new PipraPayClient($http, 'https://pay.example.com', 'test-key'))
                ->createCheckout($this->payload('500.00', $currency));
        } finally {
            $http->assertNothingSent();
        }
    }

    #[DataProvider('invalidCurrencies')]
    public function test_configured_currency_uses_the_same_validation(string $currency): void
    {
        $this->expectException(PipraPayConfigurationException::class);
        new PipraPayClient(new Factory(), 'https://pay.example.com', 'test-key', currency: $currency);
    }

    public static function invalidCurrencies(): array
    {
        return [['123!'], ['US'], ['ABCDEFGHIJK'], ["BDT\0"]];
    }

    #[DataProvider('validAmounts')]
    public function test_checkout_emits_plain_decimal_amounts(mixed $value, string $expected): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake(['*' => Factory::response(['pp_id' => 'PP-123', 'pp_url' => 'https://pay.example.com/payment/PP-123'])]);
        (new PipraPayClient($http, 'https://pay.example.com', 'test-key'))
            ->createCheckout($this->payload($value, ' usdt '));
        $http->assertSent(fn ($request): bool => $request['amount'] === $expected && $request['currency'] === 'USDT');
    }

    #[DataProvider('validAmounts')]
    public function test_verified_amounts_are_decimal_strings_without_precision_loss(mixed $value, string $expected): void
    {
        $response = PaymentResponse::fromArray(['pp_id' => 'PP-123', 'status' => 'completed', 'amount' => $value, 'currency' => 'BDT']);
        $this->assertSame($expected, $response->amount);
    }

    public static function validAmounts(): array
    {
        return [
            ['500.123456789012345678', '500.123456789012345678'],
            ['000500.00', '000500.00'], [500, '500'], [0.1, '0.1'],
            [0.000001, '0.000001'], [1.0e20, '100000000000000000000'],
            ['0.'.str_repeat('0', 330).'1', '0.'.str_repeat('0', 330).'1'],
            [str_repeat('9', 320).'.01', str_repeat('9', 320).'.01'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_non_decimal_checkout_amounts_are_rejected(mixed $value): void
    {
        $http = new Factory();
        $http->fake();
        $this->expectException(PipraPayResponseException::class);
        try {
            (new PipraPayClient($http, 'https://pay.example.com', 'test-key'))->createCheckout($this->payload($value));
        } finally {
            $http->assertNothingSent();
        }
    }

    #[DataProvider('invalidAmounts')]
    public function test_non_decimal_verified_amounts_are_rejected(mixed $value): void
    {
        $this->expectException(PipraPayResponseException::class);
        PaymentResponse::fromArray(['pp_id' => 'PP-123', 'status' => 'completed', 'amount' => $value, 'currency' => 'BDT']);
    }

    public static function invalidAmounts(): array
    {
        return [['5e2'], ['-0'], ['-0.000'], ['+5'], ['0'], [NAN], [INF], [[]], [true]];
    }

    public function test_pending_zero_remains_valid(): void
    {
        $payment = PaymentResponse::fromArray(['pp_id' => 'PP-123', 'status' => 'pending', 'amount' => '0.00', 'currency' => 'BDT']);
        $this->assertSame('0.00', $payment->amount);
        $this->assertTrue($payment->isPending());
    }

    #[DataProvider('serializerSettings')]
    public function test_float_amounts_do_not_depend_on_serializer_settings(string $precision, float $value, string $expected): void
    {
        $previous = ini_set('serialize_precision', $precision);
        try {
            $this->test_checkout_emits_plain_decimal_amounts($value, $expected);
            $this->test_verified_amounts_are_decimal_strings_without_precision_loss($value, $expected);
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    public static function serializerSettings(): array
    {
        return [['2', 123.45, '123.45'], ['17', 0.1, '0.1']];
    }

    private function payload(mixed $amount, string $currency = 'BDT'): array
    {
        return ['full_name' => 'Demo User', 'email_address' => 'demo@example.com', 'mobile_number' => '01700000000', 'amount' => $amount, 'currency' => $currency];
    }
}
