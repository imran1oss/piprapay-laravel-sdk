# PipraPay Laravel SDK

[![Tests](https://github.com/imran1oss/piprapay-laravel-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/imran1oss/piprapay-laravel-sdk/actions/workflows/tests.yml)

A small, typed Laravel 12/13 client for the current self-hosted PipraPay API.

This package creates redirect checkouts, verifies payments against PipraPay, and builds a direct checkout URL for a selected PipraPay gateway. It intentionally does not update deposits, wallets, promotions, or application databases.

## Requirements

- PHP 8.2+ for Laravel 12; PHP 8.3+ for Laravel 13
- Laravel 12 or 13
- A self-hosted PipraPay instance
- A PipraPay API key with `Create Payment` and `Verify Payment` scopes

## Installation

Until the package is published on Packagist, add the GitHub repository to your Laravel project:

```bash
composer config repositories.piprapay-laravel-sdk vcs https://github.com/imran1oss/piprapay-laravel-sdk
composer require imran1oss/piprapay-laravel-sdk:dev-main
```

Laravel package discovery registers the service provider and facade automatically.

Publish the configuration if you want to customize it:

```bash
php artisan vendor:publish --tag=piprapay-config
```

## Configuration

Add these values to `.env`:

```dotenv
PIPRAPAY_BASE_URL=https://pay.example.com
PIPRAPAY_API_KEY=your-api-key
PIPRAPAY_CURRENCY=BDT
PIPRAPAY_TIMEOUT=15
PIPRAPAY_CONNECT_TIMEOUT=5
PIPRAPAY_ATTEMPTS=3
PIPRAPAY_RETRY_SLEEP_MS=200
```

`PIPRAPAY_ATTEMPTS` and `PIPRAPAY_RETRY_SLEEP_MS` apply to payment verification
(including webhook verification). Checkout creation is sent exactly once, even
on a timeout or server error, because remote idempotency support has not been
established. An unsuccessful response may still mean the remote checkout was
created: reconcile it before manually creating another checkout.

API requests do not follow redirects, so an API key cannot be forwarded to a
redirect destination. Configure the final PipraPay API base URL; use HTTPS in
production. A redirect is reported as a `PipraPayRequestException`.

PipraPay requires the domains used by `return_url` and `webhook_url` to be active in its Domains settings.

## Create a direct gateway checkout

Use your local `gateway_currencies.id` to load the gateway row, then pass its `external_gateway_code` to `directUrl()`.

```php
use Imran1oss\PipraPay\Facades\PipraPay;

$checkout = PipraPay::createCheckout([
    'full_name' => $user->fullname,
    'email_address' => $user->email,
    'mobile_number' => $user->mobile,
    'amount' => $deposit->final_amount,
    'currency' => $deposit->method_currency,
    'return_url' => route('piprapay.return'),
    'webhook_url' => route('piprapay.webhook'),
    'metadata' => [
        'deposit_id' => $deposit->id,
        'deposit_trx' => $deposit->trx,
        'user_id' => $user->id,
    ],
]);

$deposit->update(['external_payment_id' => $checkout->paymentId]);

return redirect()->away(
    $checkout->directUrl($gatewayCurrency->external_gateway_code)
);
```

The generated URL has this form:

```text
https://pay.example.com/payment/PP_ID?gateway=EXTERNAL_GATEWAY_CODE
```

It skips PipraPay's general gateway list and opens only the selected gateway.

Existing `gateway` query parameters are replaced. Other query parameters and any
URL fragment are preserved, with the gateway query placed before the fragment.

## Verify a payment

Never credit a wallet from browser query parameters alone.

```php
$payment = PipraPay::verifyPayment($deposit->external_payment_id);

if ($payment->isCompleted()) {
    // Lock the deposit row and credit the wallet exactly once.
}
```

Verification rejects responses with a different payment ID or invalid amount,
currency, or field types. Amounts must be finite; completed payments must have a
positive amount. Decimal amount strings are preserved. Applications must still
match the verified amount, currency, and metadata to their deposit and retain
their transaction and row-lock safeguards.

Available status helpers:

```php
$payment->isCompleted();
$payment->isPending();
$payment->isCanceled();
$payment->isRefunded();
```

## Verify a webhook

The current PipraPay webhook delivery does not provide a cryptographic signature. `verifyWebhook()` therefore ignores the claimed webhook status and verifies `pp_id` against the PipraPay API.

```php
use Illuminate\Http\Request;
use Imran1oss\PipraPay\Facades\PipraPay;

public function webhook(Request $request)
{
    $payment = PipraPay::verifyWebhook($request->all());

    if ($payment->isCompleted()) {
        // Use a database transaction and row lock.
        // Check that amount, currency, and metadata match the deposit.
        // Credit the wallet only when the deposit is not already completed.
    }

    return response()->json(['received' => true]);
}
```

## Dependency injection

You may inject the contract instead of using the facade:

```php
use Imran1oss\PipraPay\Contracts\PipraPayClientInterface;

public function __construct(
    private readonly PipraPayClientInterface $pipraPay,
) {
}
```

## Error handling

```php
use Imran1oss\PipraPay\Exceptions\PipraPayException;
use Imran1oss\PipraPay\Exceptions\PipraPayRequestException;

try {
    $payment = PipraPay::verifyPayment($paymentId);
} catch (PipraPayRequestException $exception) {
    report($exception);

    $statusCode = $exception->statusCode;
    $errorCode = $exception->errorCode;
} catch (PipraPayException $exception) {
    report($exception);
}
```

## Security rules

- Keep `PIPRAPAY_API_KEY` on the server.
- Never trust the return URL or webhook status without `verifyPayment()`.
- Match the verified payment ID, amount, currency, and metadata to the local deposit.
- Make deposit completion idempotent with a database transaction and row lock.
- Do not log API keys or full sensitive payment payloads.

## Testing

```bash
composer install
composer test
```

## License

The MIT License.
