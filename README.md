# IndiPay

[![Tests](https://github.com/RenderbitTechnologies/indipay/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/RenderbitTechnologies/indipay/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.1%E2%80%938.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-5.6%E2%80%9312-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Guzzle](https://img.shields.io/badge/Guzzle-5%E2%80%937-4B8BBE)](https://docs.guzzlephp.org/)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-10%20%7C%2011-3C9CD7)](https://phpunit.de/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A Laravel package for Indian payment gateways.

| Gateway | Name to use in code | Verify support |
|---|---|---|
| [CCAvenue](https://www.ccavenue.com/) | `CCAvenue` | No |
| [PayUMoney](https://www.payumoney.com/) | `PayUMoney` | No |
| [EBS](https://www.ebs.in) | `EBS` | No |
| [CitrusPay](http://www.citruspay.com/) | `Citrus` | No |
| [InstaMojo](https://www.instamojo.com/) | `InstaMojo` | No |
| [ZapakPay](https://pay.mobikwik.com/) (Mobikwik) | `ZapakPay` | No |
| [Paytm](https://dashboard.paytm.com/) | `Paytm` | Yes |
| [Mocker](https://mocker.in) | `Mocker` | Yes |

[Laravel 4.2 version](https://github.com/softon/indipay/tree/laravel4)

## Requirements

- Laravel 5.6 to 12
- Guzzle 5, 6 or 7

CI tests the package on PHP 8.1 (Laravel 10) and PHP 8.2–8.4 (Laravel 11 and 12).

## Installation

**Step 1:** Install the package with Composer.

```bash
composer require softon/indipay
```

Laravel finds the service provider and the `Indipay` facade on its own.

**Step 2:** Publish the config file and middleware.

```bash
php artisan vendor:publish --provider="Softon\Indipay\IndipayServiceProvider"
```

This creates `config/indipay.php` and `app/Http/Middleware/VerifyCsrfMiddleware.php`.

**Step 3:** Turn off CSRF checks on the gateway response routes. Gateways post back to your app without a CSRF token, so these routes must skip the check. List them under `remove_csrf_check` in `config/indipay.php` (default: `indipay/response`), then swap in the published middleware.

*Laravel 10 and below:* in `app/Http/Kernel.php`, change

```php
App\Http\Middleware\VerifyCsrfToken::class,
```

to

```php
App\Http\Middleware\VerifyCsrfMiddleware::class,
```

> You may instead keep `VerifyCsrfToken` and add the response routes to its `$except` array.

*Laravel 11 and above:* there is no `app/Http/Kernel.php`. Swap the middleware in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(replace: [
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class => \App\Http\Middleware\VerifyCsrfMiddleware::class,
    ]);
})
```

> You may instead skip the published middleware and list the response routes with `$middleware->validateCsrfTokens(except: ['indipay/response']);`

## Configuration

Edit `config/indipay.php`:

- `gateway`: the default gateway (one of the names in the table above). Defaults to `Mocker`.
- `testMode`: `true` sends requests to the gateway's sandbox. Set it to `false` in production. **ZapakPay has no sandbox URL:** it posts to the live API in both modes.
- One block per gateway (`ccavenue`, `payumoney`, `ebs`, `citrus`, `instamojo`, `zapakpay`, `paytm`, `mocker`) for keys and return URLs. Most values read from `.env` variables, such as `INDIPAY_MERCHANT_ID`, `INDIPAY_WORKING_KEY` or `PAYTM_MERCHANT_KEY`.
- `ebs.hashAlgorithm`: the hash algorithm your EBS payment page is set to: `md5`, `sha1` or `sha512` (case-insensitive). Read from `INDIPAY_EBS_HASH_ALGORITHM` and defaults to `md5`. It is used for both the request `secure_hash` and the response `SecureHash` check, so it must match the algorithm selected for the page in your EBS account. Any other value makes `EBSGateway` throw an `InvalidArgumentException` when it is created. If your published `config/indipay.php` predates this option, the gateway falls back to `md5`.
- `remove_csrf_check`: routes the published middleware lets through without a CSRF token.

Return URLs are route paths, passed through Laravel's `url()` helper.

## Usage

```php
use Softon\Indipay\Facades\Indipay;
```

Start a payment with the default gateway and redirect the user:

```php
// Required parameters differ for each gateway. See your gateway's manual.
$parameters = [
    'transaction_no' => '1233221223322',
    'amount' => '1200.00',
    'name' => 'Jon Doe',
    'email' => 'jon@doe.com',
];

$order = Indipay::prepare($parameters);
return Indipay::process($order);
```

The package checks that each gateway's required parameters are present and throws `Softon\Indipay\Exceptions\IndipayParametersMissingException` if any are missing.

Use a gateway other than the default:

```php
// CCAvenue / PayUMoney / EBS / Citrus / InstaMojo / ZapakPay / Paytm / Mocker
$order = Indipay::gateway('Paytm')->prepare($parameters);
return Indipay::process($order);
```

Handle the gateway's response at the return URL set in the config file:

```php
public function response(Request $request)
{
    // Default gateway
    $response = Indipay::response($request);

    // Any other gateway: use the same name as in the request
    $response = Indipay::gateway('NameOfGatewayUsedDuringRequest')->response($request);

    dd($response);
}
```

What `Indipay::response` checks and returns depends on the gateway:

| Gateway | How the response is checked | Returns |
|---|---|---|
| CCAvenue | Decrypted with your working key | Array |
| PayUMoney | Hash | Array, or the string `Hash Mismatch Error` |
| Citrus | Signature | Array, or the string `Hash Mismatch Error` |
| ZapakPay | Checksum | Array, or the string `Recieved Checksum Mismatch.` |
| Paytm | Checksum | Array with a `status` key: `success` or `failure` |
| InstaMojo | Fetched from the InstaMojo API | Object, or `false` |
| EBS | SecureHash | Array, or the string `Hash Mismatch Error` |
| Mocker | **Not checked** | Array with a `status` key: `success` or `failure` |

**Every signed field must be present.** For PayUMoney and Citrus, a callback that leaves out any field that is part of the hash is rejected with `Hash Mismatch Error`, even when the field's value would have been empty. The fields are `status`, `udf1` to `udf5`, `email`, `firstname`, `productinfo`, `amount`, `txnid` and `key` for PayUMoney, and `TxId`, `TxStatus`, `amount`, `pgTxnNo`, `issuerRefNo`, `authIdCode`, `firstName`, `lastName`, `pgRespCode` and `addressZip` for Citrus. A field may be present with an empty value. This way the array you get back is exactly the callback the gateway signed. If your gateway account omits empty fields from its callbacks, they will all fail this check.

**Mocker responses are not checked.** Anyone can post fake data to your response route, since it skips CSRF checks. Confirm the payment with the gateway (for example with `verify()`) before you fulfil an order.

Save the transaction details to your database before you send the user to the gateway, so you can check the payment status later.

## Payment Verification

Paytm and Mocker can look up a payment's status, for example when it is still pending:

```php
// Paytm
$order = Indipay::gateway('Paytm')->verify([
    'ORDERID' => '3322344231223',
]);

// Mocker
$order = Indipay::gateway('Mocker')->verify([
    'transaction_no' => '3322344231223',
    'amount' => '1200.00',
]);
```

## Testing

```bash
composer install
composer test
```

`composer.lock` pins Laravel 12, which needs PHP 8.2 or later. On PHP 8.1, run `composer update` instead of `composer install` to get versions that work with it.

Tests use [Orchestra Testbench](https://github.com/orchestral/testbench) and make no network calls.

## License

MIT. See [LICENSE](LICENSE).
