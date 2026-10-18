# Changelog

## Unreleased

### Changed

- **ZapakPay: the transaction API moves from `/api/paymentTransact/V7` to `/api/paymentTransact/V13`** on both the live (`https://api.zaakpay.com`) and staging (`https://zaakstaging.zaakpay.com`) hosts. This affects production integrations as well as `testMode`. Confirm V13 is enabled on your Zaakpay account and test against staging before upgrading.
- ZapakPay response verification now also covers `productDescription`, `product1Description` to `product4Description`, `pgTransId` and `pgTransTime` when Zaakpay sends them. Responses without those fields verify as before.
- ZapakPay signs the V13 request options `couAiId`, `couRefId`, `isAutoRedirect`, `isPasskeySupported` and `paymentOptionTypes` when they are passed. Zaakpay's docs are ambiguous about the request checksum order, so these are placed alphabetically; confirm their position with Zaakpay before relying on them. Requests that don't use them are unchanged.
