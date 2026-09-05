# PS8 browser and historical upgrade verification — 2026-09-05

## Findings and fixes

A real 0.2.7 installation exposed a migration defect hidden by the former simulated fixture. Its rate-limit table requires date_upd without a default. The previous upgrade added last_failure_at but retained that required column, causing new failure-counter inserts to fail under strict SQL mode. The migration now copies missing timestamps and removes the obsolete column. It can resume when both columns already exist, and a failed backfill prevents column removal.

Browser interaction exposed a second defect: native background requests received MFA redirects while the employee was already completing enrollment or verification. The XHR listener repeatedly navigated to the same form. It now preserves the current form when the destination matches, treating an omitted step_up and step_up=0 equally. A redirect requiring step_up=1 still navigates normally.

## Historical package provenance

The test creates a ZIP from the unmodified PS8 commit 9334de7296f98d4248af4b7b541038ad34da22cc, the last 0.2.7 revision before the audit fixes. It installs that revision's production dependencies from its own Composer lock and records the commit plus a ZIP checksum. This is a source-built historical package, with Git revision provenance; no published release ZIP is claimed.

The disposable shop runs the historical install command before the current scoped package replaces its files and the native module upgrade command runs. The fixture verifies the old installer, installed version, missing dispatcher hook, and original timestamp column. It never changes installed-version metadata to simulate an old installation.

Assertions cover:

- Preservation of all six tables' existing records, including keyring, encrypted factor, recovery hash, approval, audit, and rate-limit history.
- Decryption of the historical factor by the new scoped crypto, unused recovery-code validity, and preserved policy settings.
- New failure-counter inserts and atomic increments under strict SQL mode.
- Recovery from the previous partially migrated schema, repeat execution, tabs, native permissions, hooks, and full uninstall cleanup.

The historical output directory stays outside the current source/build tree. Replacement is restricted to a separate installed-package directory in the disposable shop; the harness restores the current package on exit.

## Browser coverage

Playwright drives Chromium against the same isolated Apache HTTPS runtime as the existing request suite. The browser trusts only that runtime's generated certificate public key. Tests use native password forms, real CSRF tokens, independently generated TOTP codes, and the installed package's actual JavaScript.

Coverage includes first-SuperAdmin enrollment and QR rendering, code confirmation, recovery-code acknowledgement and one-time display, a fresh browser login with MFA, background-request form stability, duplicate listener loading, ordinary XHR behavior, real step-up expiry and XHR navigation, JavaScript-disabled legacy enforcement and form submission, fresh-step-up admission, and recovery replacement restrictions.

The browser runner stores failure details only in the private request-runtime directory. It does not enable traces or public failure artifacts that could expose passwords, OTPs, setup keys, or recovery codes. Console output uses assertion labels and the failed phase.

Browser dependencies and their lock are confined to tests/Browser; the existing release builder excludes all tests. Classic and back-office asset builds retain Node 16, followed by Node 22 for browser testing. The CI-generated and masked MP2FA_TEST_EMAIL / MP2FA_TEST_PASSWORD flow is unchanged.

## Running

Install the isolated browser dependencies once:

~~~sh
npm ci --prefix tests/Browser
(cd tests/Browser && npx playwright install --with-deps chromium)
~~~

Build the current scoped package, then prepare the historical fixture from a checkout containing the pinned commit:

~~~sh
MP2FA_HISTORICAL_OUTPUT=/absolute/path/outside/module/historical \
  bash tests/Integration/build_historical_package.sh
~~~

Install a disposable shop and place the current scoped package in its module directory. Supply the generated credentials used by that shop's installer:

~~~sh
MP2FA_INTEGRATION=1 \
MP2FA_PS_ROOT=/absolute/path/to/disposable/shop \
MP2FA_HISTORICAL_OUTPUT=/absolute/path/outside/module/historical \
MP2FA_TEST_EMAIL="$disposable_email" \
MP2FA_TEST_PASSWORD="$disposable_password" \
  bash tests/Integration/run_lifecycle.sh
~~~

CHROME_BIN optionally selects an installed Chromium binary. The lifecycle and request fixtures replace module data and alter the disposable shop's SSL/origin configuration; never target an operational shop.

## Validation

Local verification used a disposable copy of PS 8.2.8, PHP 8.1.33, MySQL 5.7.44, Node 20.20.2, and Chromium 152.0.7977.75. The source shop was mounted read-only; the application ran as www-data.

- Unit suites on PHP 7.2 and PHP 8.1: 116 tests, 548 assertions, with the two opt-in database tests skipped.
- Combined installed-package run: 64 HTTPS checks, 21 browser checks, and 2 database tests / 30 assertions passed.
- Actual historical 0.2.7 install and native upgrade, preservation checks, strict-mode writes, partial-migration repair, repeat execution, and uninstall cleanup passed.
- All five injected installation failures (schema, configuration, hook, tab, and access) rolled back completely; each was followed by successful reinstall and uninstall.
- Scoped release build, package checksums, dependency isolation, 243 PHP 7.2 syntax checks across package/tests, strict Composer validation, and workflow/shell/JavaScript syntax checks passed.

[GitHub Actions run 33981604269](https://github.com/mostlypixels/mpadmin2fa/actions/runs/33981604269) passed all six jobs on commit c1e3fec86e49a4ae368b6a0fdabf2d1ee717be11. This supersedes the earlier CI baseline at bdd0ff9.

Both packaged lifecycle targets, PS 8.0.0 / PHP 7.2 and PS 8.2.8 / PHP 8.1, completed 64 HTTPS checks, 21 browser checks, 2 database tests / 30 assertions, the pinned historical 0.2.7 upgrade with data-preservation and repair checks, and all five rollback/reinstallation stages. The three unit-test jobs and isolated scoped-package job also passed. No CI-specific product or harness correction was needed after local validation.

The surrounding PS8 checkout remains unchanged. The obsolete audit remediation plan was removed after delivery and successful CI validation; these verification checkpoints retain the findings, fixes, evidence, and coverage boundaries.
