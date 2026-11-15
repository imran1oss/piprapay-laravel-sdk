<?php

namespace Imran1oss\PipraPay\Tests;

use Imran1oss\PipraPay\Data\CheckoutResponse;
use Imran1oss\PipraPay\Exceptions\PipraPayResponseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CheckoutResponseTest extends TestCase
{
    #[DataProvider('checkoutUrls')]
    public function test_gateway_query_preserves_fragments_and_unrelated_parameters(string $url, string $expected): void
    {
        $checkout = CheckoutResponse::fromArray(['pp_id' => 'PP-123', 'pp_url' => $url]);
        $this->assertSame($expected, $checkout->directUrl('GW BKASH'));
    }

    public static function checkoutUrls(): array
    {
        $base = 'https://pay.example.com/payment/PP-123';

        return [
            [$base, $base.'?gateway=GW%20BKASH'],
            [$base.'?lang=bn', $base.'?lang=bn&gateway=GW%20BKASH'],
            [$base.'#details', $base.'?gateway=GW%20BKASH#details'],
            [$base.'?lang=bn#details', $base.'?lang=bn&gateway=GW%20BKASH#details'],
            [$base.'?gateway=OLD&lang=bn#details', $base.'?lang=bn&gateway=GW%20BKASH#details'],
            [$base.'?%67ateway=OLD', $base.'?gateway=GW%20BKASH'],
            [$base.'?gateway=A&gateway=B', $base.'?gateway=GW%20BKASH'],
            [$base.'?tag=a&tag=b&token=a%2Bb', $base.'?tag=a&tag=b&token=a%2Bb&gateway=GW%20BKASH'],
            [$base.'#', $base.'?gateway=GW%20BKASH#'],
        ];
    }

    #[DataProvider('invalidCheckoutResponses')]
    public function test_invalid_checkout_fields_are_rejected(array $payload): void
    {
        $this->expectException(PipraPayResponseException::class);
        CheckoutResponse::fromArray($payload);
    }

    public static function invalidCheckoutResponses(): array
    {
        return [
            [['pp_id' => 'PP-123', 'pp_url' => 'ftp://pay.example.com/payment/PP-123']],
            [['pp_id' => [], 'pp_url' => 'https://pay.example.com/payment/PP-123']],
            [['pp_id' => 'PP-123', 'pp_url' => []]],
            [['pp_id' => '', 'pp_url' => 'https://pay.example.com/payment/PP-123']],
        ];
    }

    public function test_empty_gateway_code_is_rejected(): void
    {
        $checkout = new CheckoutResponse('PP-123', 'https://pay.example.com/payment/PP-123');
        $this->expectException(PipraPayResponseException::class);
        $checkout->directUrl(' ');
    }
}
