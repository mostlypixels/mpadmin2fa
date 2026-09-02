# PS8 lifecycle/package verification — 2026-09-02

This checkpoint covers the module-owned install boundary and the actual scoped package. It does not close the remaining request/browser audit items.

## Changes

- Create/reconcile tabs and ordinary-profile navigation access inside `install()`; leave `postInstall()` as a repair entry point.
- Roll back module tables, configuration, native registration, hooks, tabs, roles and access when installation fails.
- Reject a repeated install before cleanup can touch an existing installation.
- Preserve module and PrestaShop namespaces when scoping dependencies; provide the conventional Composer loader bridge.
- Exclude the nested PrestaShop checkout and give the scoped package a distinct Composer loader.
- Add disposable installed-package CI on PS 8.0.0 / PHP 7.2 and PS 8.2.8 / PHP 8.1.

## Local evidence

Tested with PrestaShop 8.2.8, PHP 8.1.33 and MySQL 5.7.44 in disposable containers. The surrounding checkout was mounted read-only for installed-package tests.

- Unit suite: 98 tests, 372 assertions; two opt-in database tests skipped.
- Installed-package database suite: 2 tests, 30 assertions, including ten concurrent rate-limit writers.
- Scoped build, namespace/dual-loader/TOTP smoke checks and release checksums passed.
- All 211 packaged PHP files passed PHP 7.2 syntax checks.
- Clean install: six module tables, seven configuration keys, seven tabs, six required hooks, and read access to three navigation tabs for every profile.
- Ordinary profiles received no additional privileged module-tab permissions.
- Repeated install was rejected without changing the active encryption key or removing the module.
- Production container compilation and module route discovery passed.
- Simulated 0.2.7 schema/hook/access upgrade to 0.2.8 passed.
- Database-trigger failures at schema, configuration, hook, tab and profile-access stages each caused install failure and complete module cleanup.
- Every failed attempt was followed by a successful reinstall and uninstall.
- Cleanup verified module registration, tables, configuration, tabs, authorization roles and orphan native/access records. Stock PS8 zero-role access fixtures are excluded from the orphan check.
- Stock Twig PHP 8.1 deprecation notices appeared during production cache compilation; compilation still succeeded.

## Running the installed-package checks

Build the module, install a disposable PrestaShop shop, then copy only `build/mpadmin2fa/` into its module directory. Keep the source module's development dependencies available to the external test runner.

```sh
MP2FA_INTEGRATION=1 MP2FA_PS_ROOT=/absolute/path/to/disposable/shop \
  bash tests/Integration/run_lifecycle.sh
```

This command is destructive to the module's data in the selected shop. It creates an ordinary test profile and database triggers, installs/upgrades/uninstalls the module, and intentionally rejects inserts. Never target a real shop.

## Still pending

- Baseline remote lifecycle matrix passed on both targets in [run 33618337257](https://github.com/mostlypixels/mpadmin2fa/actions/runs/33618337257). The later request checkpoint is tracked in [ps8-request-verification.md](ps8-request-verification.md).
- Full installed-package request/browser parity and the remaining security request scenarios; see the request checkpoint for the completed login/MFA slice.
- A genuine historical-package upgrade matrix beyond the simulated 0.2.7 schema/hook/access fixture.

The existing `documentation/audit-fix-plan.md` edits were preserved and are not included in this checkpoint.
