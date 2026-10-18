# Changelog

## Unreleased

## 4.0.0 - 2026-10-18

This release covers every change since `v3.2.0`. Read the breaking changes before upgrading from 3.x.

### Breaking changes

- **Requirements:** PHP `^7.1.3|^8.0` (was `>=5.4.0`) and Guzzle `~6.0|^7.0` (Guzzle 5 is no longer supported).
- **ZapakPay: the transaction API moves from `/api/paymentTransact/V7` to `/api/paymentTransact/V13`** on both the live (`https://api.zaakpay.com`) and staging (`https://zaakstaging.zaakpay.com`) hosts. This affects production integrations as well as `testMode`. Confirm V13 is enabled on your Zaakpay account and test against staging before upgrading.
- ZapakPay `testMode` now posts to the Zaakpay staging host instead of the live one.
- ZapakPay signs the values it actually posts (sanitized, with `'0'` values included), so checksums differ from 3.x for some requests.
- `Indipay::gateway($name)` and the configured default gateway throw `InvalidArgumentException` for an unknown gateway name instead of silently keeping the current gateway. Names are matched case-insensitively.
- `Indipay::verify()` throws `UnsupportedOperationException` for gateways that don't implement `VerifiesPayments`.
- InstaMojo `request()` throws `IndipayGatewayException` (with InstaMojo's error message or the HTTP status) when the payment request fails, instead of crashing later in `send()`.
- `IndipayParametersMissingException` renders as a 422 response (JSON when the request expects JSON) instead of a 200. The `MessageBag` is still available through `errors()`.
- Paytm `verify()` requires the order `amount` and returns `false` without it. It reports success only when `STATUS` is `TXN_SUCCESS` and the order ID, MID (when present) and amount match. Status calls now go through Guzzle with TLS verification and return `null` on transport errors or non-200 replies.
- Removed unused Paytm methods: `getChecksumFromString()`, `verifychecksum_eFromStr()`, `redirect2PG()`, `initiateTxnRefund()`, `getRefundChecksumFromArray()`, `getRefundArray2Str()` and `callRefundAPI()`, plus the aliases `getTxnStatus()` (use `getTxnStatusNew()`) and `callAPI()` (use `callNewAPI()`).
- Removed the unused `PaytmGateway::$merchantData`, `InstaMojoGateway::$merchantData` and `InstaMojoGateway::$encRequest` properties and `CCAvenueGateway::pkcs5_pad()`. Subclasses that relied on them need to declare their own.
- `ZapakPayGateway::verifyChecksum()` and `PaytmGateway::verifychecksum_e()` return `bool` instead of `1`/`0` or `"TRUE"`/`"FALSE"`.
- CCAvenue `request()` rejects `&` and `=` in field names and `&` in values. `response()` rejects duplicate keys, normalises keys like `parse_str`, and returns `'Decryption Error'` when `encResp` is missing or malformed.
- PayUMoney rejects conflicting `additionalCharges` aliases, ignores `udf6` to `udf10` in the request hash, and includes `additionalCharges` in the response hash.
- The service provider is no longer deferred (`$defer` and `provides()` are removed).
- The published CSRF middleware stub now overrides only `inExceptArray()`, keeping Laravel's own `$except`, test bypass and XSRF cookie handling. Apps that published the old stub should re-publish it.

### Added

- `INDIPAY_GATEWAY` and `INDIPAY_TEST_MODE` environment variables for the `gateway` and `testMode` config (defaults unchanged).
- An `indipay-config` publish tag for publishing only the config file.
- InstaMojo and Paytm accept an optional `GuzzleHttp\ClientInterface` in the constructor.
- ZapakPay signs the V13 request options `couAiId`, `couRefId`, `isAutoRedirect`, `isPasskeySupported` and `paymentOptionTypes` when they are passed. Zaakpay's docs are ambiguous about the request checksum order, so these are placed alphabetically; confirm their position with Zaakpay before relying on them. Requests that don't use them are unchanged.
- Redirect views use an HTML5 layout with a CSP nonce (read from Laravel's Vite instance) and a `<noscript>` fallback button.
- CI now tests Laravel 5.6 to 9 as well as 10 to 12, on the PHP, Testbench and PHPUnit versions listed in the README. The suite itself now also runs on PHPUnit 7 to 9 (`phpunit.legacy.xml`).

### Changed

- ZapakPay response verification now also covers `productDescription`, `product1Description` to `product4Description`, `pgTransId` and `pgTransTime` when Zaakpay sends them. Responses without those fields verify as before.
- `request()` on every gateway starts from the constructor's defaults, so a reused gateway instance no longer carries fields over from a previous payment.
- Paytm and ZapakPay compare checksums with `hash_equals`. Paytm salts and gateway transaction IDs use a CSPRNG.

### Fixed

- ZapakPay: `productInfo` (a JSON document) is now posted and signed exactly as given instead of going through the generic sanitizer, which stripped its braces, quotes, colons and commas. An array passed as `productInfo` is JSON-encoded, and one that cannot be encoded is rejected. Zaakpay's docs don't say how `productInfo` is included in the checksum, so it is signed raw; check it against Zaakpay's checksum tool before relying on it.
- InstaMojo validates callback IDs before building the API URL and handles non-JSON replies.
- Mocker `response()` no longer throws when `transaction_status` is missing.
- No PHP 8.2+ deprecations from dynamic properties or `srand()`.
- The package config is merged with the app's, so missing keys fall back to the defaults.
