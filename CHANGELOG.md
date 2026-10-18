# Changelog

## Unreleased

### Added

- CI now tests Laravel 5.6 to 9 as well as 10 to 12, on the PHP, Testbench and PHPUnit versions listed in the README. The declared range (`illuminate/support` 5.6 to 12, PHP `^7.1.3|^8.0`) is unchanged. The suite itself now also runs on PHPUnit 7 to 9 (`phpunit.legacy.xml`).

### Fixed

- ZapakPay: `productInfo` (a JSON document) is now posted and signed exactly as given instead of going through the generic sanitizer, which stripped its braces, quotes, colons and commas. An array passed as `productInfo` is JSON-encoded. Zaakpay's docs don't say how `productInfo` is included in the checksum, so it is signed raw; check it against Zaakpay's checksum tool before relying on it.

### Changed

- **ZapakPay: the transaction API moves from `/api/paymentTransact/V7` to `/api/paymentTransact/V13`** on both the live (`https://api.zaakpay.com`) and staging (`https://zaakstaging.zaakpay.com`) hosts. This affects production integrations as well as `testMode`. Confirm V13 is enabled on your Zaakpay account and test against staging before upgrading.
- ZapakPay response verification now also covers `productDescription`, `product1Description` to `product4Description`, `pgTransId` and `pgTransTime` when Zaakpay sends them. Responses without those fields verify as before.
- ZapakPay signs the V13 request options `couAiId`, `couRefId`, `isAutoRedirect`, `isPasskeySupported` and `paymentOptionTypes` when they are passed. Zaakpay's docs are ambiguous about the request checksum order, so these are placed alphabetically; confirm their position with Zaakpay before relying on them. Requests that don't use them are unchanged.
