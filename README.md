# IndiPay

[![Tests](https://github.com/RenderbitTechnologies/indipay/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/RenderbitTechnologies/indipay/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.1%E2%80%938.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-5.6%E2%80%9312-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Guzzle](https://img.shields.io/badge/Guzzle-5%E2%80%937-4B8BBE)](https://docs.guzzlephp.org/)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-10%20%7C%2011-3C9CD7)](https://phpunit.de/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A Laravel package for Indian payment gateways.

> **About this fork:** This is a maintained fork of [softon/indipay](https://github.com/softon/indipay). The upstream package is no longer maintained and appears to be abandoned, so this fork carries on its development, including support for newer versions of PHP and Laravel. See [Installation](#installation) for how to install it in place of the upstream package.

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

**Step 1:** Install the package from this fork with Composer. It is not published as a separate package on Packagist. The fork keeps the upstream package name, `softon/indipay`, so you point Composer at this repository and it uses that in place of the upstream release.

Add the repository to your app's `composer.json`:

```bash
composer config repositories.indipay vcs https://github.com/RenderbitTechnologies/indipay
```

This adds the following to `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/RenderbitTechnologies/indipay"
    }
]
```

Then require the latest 3.x release:

```bash
composer require "softon/indipay:^3.2"
```

This installs the newest `3.x` tag at or above `v3.2.0`. To follow unreleased changes on the `master` branch instead, require `softon/indipay:dev-master`.

Laravel finds the service provider and the `Indipay` facade on its own.

> If you already installed the upstream package, run the `composer config` command above and then `composer update softon/indipay`. Check that `composer.lock` now lists `https://github.com/RenderbitTechnologies/indipay` as the package source.
>
> For a private clone or SSH access, use `git@github.com:RenderbitTechnologies/indipay.git` as the repository URL.

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
- `payumoney.strictCallbackFields` and `citrus.strictCallbackFields`: when `true`, `response()` rejects a callback that leaves out a field covered by its hash. Read from `INDIPAY_STRICT_CALLBACK_FIELDS` and defaults to `false`. See the note on absent fields below. If your published `config/indipay.php` predates this option, it is off.
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

**Absent fields count as empty.** By default, for PayUMoney and Citrus, a field that is part of the hash and left out of the callback is treated as an empty string. If the gateway signed that field with an empty value, a callback without the key still passes the check, and the array you get back has no such key. PayUMoney joins the signed values with `|`, so a field that carried a value cannot be left out. Citrus joins them with no separator, so field boundaries are not protected: a callback can drop a non-empty field if the next field's value is changed to the two values joined. Use `array_key_exists('field', $response)` to tell an absent field from an empty one.

To require every signed field, set `INDIPAY_STRICT_CALLBACK_FIELDS=true` (config keys `payumoney.strictCallbackFields` and `citrus.strictCallbackFields`). A callback that leaves out any of them is then rejected with `Hash Mismatch Error`, even if the value would have been empty. A field may still be present with an empty value. The fields are `status`, `email`, `firstname`, `productinfo`, `amount`, `txnid` and `key` for PayUMoney (the unused `udf1` to `udf5` fields may be left out, as PayU does), and `TxId`, `TxStatus`, `amount`, `pgTxnNo`, `issuerRefNo`, `authIdCode`, `firstName`, `lastName`, `pgRespCode` and `addressZip` for Citrus. Turn it on only if your gateway account always sends every one of them, because empty fields that are omitted will fail the check.

**Mocker responses are not checked.** Anyone can post fake data to your response route, since it skips CSRF checks. Confirm the payment with the gateway (for example with `verify()`) before you fulfil an order.

Save the transaction details to your database before you send the user to the gateway, so you can check the payment status later.

## Payment Verification

Paytm and Mocker can look up a payment's status, for example when it is still pending. Calling `verify()` on any other gateway throws `Softon\Indipay\Exceptions\UnsupportedOperationException`. A custom gateway opts in by implementing `Softon\Indipay\Gateways\VerifiesPayments`:

```php
// Paytm
$order = Indipay::gateway('Paytm')->verify([
    'ORDERID' => '3322344231223',
    'amount' => '1200.00',
]);

// Mocker
$order = Indipay::gateway('Mocker')->verify([
    'transaction_no' => '3322344231223',
    'amount' => '1200.00',
]);

if ($order['status'] === 'success') {
    // Paid in full: fulfil the order.
}
```

Pass the amount you expect, taken from your own records, not from the gateway's callback. Each result carries a `status` of `success` or `failure`, plus the fields the status API returned, if any.

Paytm reports `success` only when all of these hold:

- the status API says `STATUS` is `TXN_SUCCESS`
- the reply's `ORDERID` is the one you asked about
- the reply's `MID` is your configured `MID` (a reply that leaves out `MID` is accepted)
- the reply's `TXNAMOUNT` equals `amount`, compared as numbers (`'100.00'` matches `100`)

If the status call fails (a network or TLS error, a non-200 reply, or a body that is not JSON with a `STATUS`), Paytm's `verify()` returns `['status' => 'failure']` instead of throwing. It returns `false` when `ORDERID` is missing. The call checks Paytm's TLS certificate.

**Calling Paytm's `verify()` without `amount` is deprecated.** It raises a silenced `E_USER_DEPRECATED` notice (Laravel 9 and later write it to the deprecations log channel when one is set up) and accepts any amount, as before. `amount` will be required in the next major version.

Mocker's `verify()` throws `IndipayParametersMissingException` without `transaction_no` and `amount`, and reports `success` only when the reply's `amount` matches.

## Testing

```bash
composer install
composer test
```

`composer.lock` pins Laravel 12, which needs PHP 8.2 or later. On PHP 8.1, run `composer update` instead of `composer install` to get versions that work with it.

Tests use [Orchestra Testbench](https://github.com/orchestral/testbench) and make no network calls.

## License

MIT. See [LICENSE](LICENSE).
