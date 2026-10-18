# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`softon/indipay`: a Laravel package (not an app) for Indian payment gateways. This repo is a maintained fork of the abandoned upstream `softon/indipay`. It keeps the upstream package name and is installed from this GitHub repo as a VCS repository, not from Packagist. Namespace `Softon\Indipay\` → `src/`.

## Commands

```bash
composer install            # composer.lock is gitignored, so this resolves fresh
composer test               # = vendor/bin/phpunit (phpunit.xml)
vendor/bin/phpunit --filter test_name_or_regex
vendor/bin/phpunit tests/Gateways/PaytmGatewayTest.php
```

There is no linter or build step. Tests use Orchestra Testbench and make no network calls.

## Supported range and legacy CI

The declared range is very wide: PHP `^7.1.3|^8.0`, `illuminate/support` 5.6 to 12, Guzzle 6 or 7. `.github/workflows/tests.yml` tests each Laravel release on its oldest and newest verified PHP. Code in `src/` and `tests/` must keep working on all of them:

- No PHP 8-only syntax in `src/` (no typed properties, union types, `match`, nullsafe, named args, constructor promotion). Older code style (`array()`, `<?php namespace X;` on one line) is what the files already use.
- Tests must run on PHPUnit 7 to 11. `tests/TestCase.php` has shims for this (`assertPatternMatches`, `getProtected`, a guarded `withoutDeprecationHandling`); use them instead of newer-only PHPUnit APIs.
- Data providers use `#[DataProvider('name')]` attributes. Legacy CI jobs (Laravel ≤ 9) run `.github/scripts/legacy-data-providers.sh`, which rewrites them in place to `@dataProvider` doc-comments, then run `vendor/bin/phpunit --configuration phpunit.legacy.xml`. Keep the attribute in exactly that single-quoted form so the regex matches. Only run that script in a throwaway checkout.
- `tests/ComposerConstraintsTest.php` pins the exact `php` and `guzzlehttp/guzzle` constraints in `composer.json`; changing them means updating that test (and the README tables).
- `TestCase::setUp()` turns deprecations into failures, so package code must not raise PHP deprecations (e.g. dynamic properties; see `GatewayDeprecationTest`).

## Architecture

- `Indipay` (facade accessor `indipay`, bound in `IndipayServiceProvider`) wraps one `PaymentGatewayInterface` (`request`, `send`, `response`). The default gateway is resolved from `config('indipay.gateway')` via the provider's name→class map. `Indipay::gateway($name)` swaps it via a separate `switch` in `src/Indipay.php`. **Adding a gateway means updating both places**, plus `src/config/config.php`, a view, tests and the README tables. Unknown names throw `InvalidArgumentException` in both paths.
- Flow: `prepare($params)` → gateway `request()` merges params over the constructor-built defaults, validates (throws `IndipayParametersMissingException`) and computes the hash/encryption; `process($order)` → `send()` returns a Blade view (`src/views/*.blade.php`, namespace `indipay::`) that auto-posts a form to the gateway endpoint (test vs live chosen by `indipay.testMode`); `response($request)` verifies the callback.
- Gateways read all config in their constructor. `request()` must start from `$this->defaultParameters` so a reused gateway instance doesn't carry fields over from a previous payment (`GatewayStateResetTest`).
- `verify()` is optional: gateways opt in by implementing `VerifiesPayments` (Paytm, Mocker). `Indipay::verify()` throws `UnsupportedOperationException` otherwise.
- Gateways that make HTTP calls (InstaMojo, Mocker, Paytm) accept an optional `GuzzleHttp\ClientInterface` in the constructor; tests inject a mock handler through it.
- `response()` return types differ per gateway (array, error string like `'Hash Mismatch Error'`, object, `false`). These are public behaviour documented in the README; don't normalise them without treating it as a breaking change.
- `src/stubs/VerifyCsrfMiddleware.stub` is published into apps so gateway POST-backs skip CSRF checks (`remove_csrf_check` config).

## Docs are part of the contract

The README documents security-relevant behaviour per gateway (signed fields, what "paid" means, strict callback fields, InstaMojo/Mocker caveats). Update it, and `CHANGELOG.md` under `## Unreleased`, when behaviour changes. `tests/ReadmeAfterResponseTest.php` asserts specific README text (the `## After the response` section, "Compare the amount", "idempotent", "payment->status"), so don't reword those away.
