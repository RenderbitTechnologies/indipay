# AGENTS.md

`softon/indipay` is a Laravel **package** (not an app) for Indian payment gateways: a maintained
fork installed from this GitHub repo as a VCS repository (not Packagist), keeping the upstream
package name. Namespace `Softon\Indipay\` → `src/`.

## Commands

```bash
composer install                 # composer.lock is gitignored, so this resolves fresh
composer test                    # = vendor/bin/phpunit (phpunit.xml)
vendor/bin/phpunit --filter <name_or_regex>
vendor/bin/phpunit tests/Gateways/PaytmGatewayTest.php
```

No linter or build step. Tests use Orchestra Testbench and make no network calls.

## Cross-version support is the top constraint

Declared range: PHP `^7.1.3|^8.0`, `illuminate/support` 5.6–12, Guzzle 6|7. CI
(`.github/workflows/tests.yml`) tests each Laravel release on its oldest and newest verified PHP,
so everything in `src/` and `tests/` must run on all of them:

- Write `src/` and `tests/` in the PHP 7.1 style the files already use (`array()`, `<?php namespace
  X;` on one line). PHP 8-only syntax — typed properties, union types, `match`, nullsafe, named
  args, constructor promotion — breaks the legacy CI jobs.
- Tests must run on PHPUnit 7 to 11. Use the shims in `tests/TestCase.php`
  (`assertPatternMatches`, `getProtected`, guarded `withoutDeprecationHandling`) instead of
  newer-only PHPUnit APIs.
- Data providers use `#[DataProvider('name')]` in exactly that single-quoted form. Legacy CI jobs
  run `.github/scripts/legacy-data-providers.sh`, which rewrites those attributes in place to
  `@dataProvider` doc-comments and runs `phpunit.legacy.xml`. Run that script only on a throwaway
  checkout.
- `tests/ComposerConstraintsTest.php` pins the exact `php` and `guzzlehttp/guzzle` constraints in
  `composer.json`; changing them means updating that test and the README tables.
- `TestCase::setUp()` turns PHP deprecations into test failures, so package code must not raise
  them (e.g. dynamic properties; see `GatewayDeprecationTest`).

## Architecture

- The `Indipay` facade (`indipay`, bound in `IndipayServiceProvider`) wraps one
  `PaymentGatewayInterface` (`request`, `send`, `response`). The default gateway comes from
  `config('indipay.gateway')`, lower-cased and looked up in `IndipayServiceProvider::$gateways`.
  `Indipay::gateway($name)` swaps the gateway through a **separate** `switch` in `src/Indipay.php`.
  Adding a gateway means updating both places, plus `src/config/config.php`, a Blade view, tests,
  and the README tables. Unknown names throw `InvalidArgumentException` in both paths.
- Flow: `prepare($params)` → the gateway `request()` merges params over the constructor-built
  `defaultParameters`, validates (throws `IndipayParametersMissingException`) and computes the
  hash/encryption; `process($order)` → `send()` returns a Blade view in `src/views/` (namespace
  `indipay::`) that auto-posts to the gateway endpoint, test vs live chosen by `indipay.testMode`;
  `response($request)` verifies the callback.
- `request()` must start from `$this->defaultParameters` so a reused gateway instance does not
  carry fields over from a previous payment (`GatewayStateResetTest`).
- `verify()` is opt-in: gateways opt in by implementing `VerifiesPayments` (Paytm, Mocker).
  `Indipay::verify()` throws `UnsupportedOperationException` otherwise.
- Gateways that make HTTP calls (InstaMojo, Mocker, Paytm) accept an optional
  `GuzzleHttp\ClientInterface` constructor argument; tests inject a mock handler through it.
- `response()` return types differ per gateway (array, an error string such as `'Hash Mismatch
  Error'`, object, `false`). These are public, README-documented behaviour — a change to one is a
  breaking change, so do not normalise them.
- `src/stubs/VerifyCsrfMiddleware.stub` is published into apps so gateway POST-backs skip CSRF
  checks (`remove_csrf_check` config).

## Docs are part of the contract

The README documents security-relevant behaviour per gateway (signed fields, what "paid" means,
strict callback fields, InstaMojo/Mocker caveats). Update it and `CHANGELOG.md` (under
`## Unreleased`) when behaviour changes. `tests/ReadmeAfterResponseTest.php` asserts specific
README text (the `## After the response` section, "Compare the amount", "idempotent",
"payment->status"), so keep those phrases.
