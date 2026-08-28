# Audit remediation plan — PrestaShop 1.7

Target branch: `1.x-ps17`

All fixes in this plan must be implemented inside `mpadmin2fa`; no PrestaShop core patch is required.

## Objectives

- Enforce MFA and step-up checks on Symfony and legacy back-office requests.
- Count failed attempts correctly under concurrency.
- Make clean install, failed install, upgrade, and uninstall deterministic on PS 1.7.
- Enforce the documented rule that another SuperAdmin approves enrollment.
- Cover the real PrestaShop lifecycle in CI, not only isolated unit tests.

## Delivery order

1. Add characterization tests and an integration-test harness.
2. Extract a shared access-policy service.
3. Gate legacy back-office requests server-side.
4. Make rate-limit increments atomic.
5. Replace the unsupported `postInstall()` dependency with a module-owned PS 1.7 tab lifecycle.
6. Enforce SuperAdmin approval.
7. Run the full compatibility and release-package matrix.

## 1. Test scaffolding and characterization

- Keep the existing unit suite, and add database-backed integration tests that boot the module in a disposable PS 1.7 installation.
- Add helpers for employees, profiles, sessions, tabs, access rows, and rate-limit rows.
- First capture the failing behavior:
  - an enrolled but unverified employee can reach a legacy admin controller;
  - a legacy `AdminModules` mutation can avoid step-up;
  - a clean install does not grant the intended tab access to an ordinary profile;
  - a failed install can leave module tabs behind;
  - concurrent failures produce a count lower than the submitted attempts;
  - a non-SuperAdmin with delegated `update` permission can approve enrollment.

## 2. Shared MFA access policy

Extract the policy from `src/EventSubscriber/AdminMfaSubscriber.php` into a framework-neutral service such as `src/Security/AdminMfaAccessPolicy.php`.

The service should accept normalized employee, route/controller, action, verification-state, and time inputs. It should return one of four decisions: allow, require MFA, require step-up, or deny. Redirects and framework response handling stay in adapters.

Both the Symfony subscriber and the legacy adapter must call this service. The policy must:

- allow only the endpoints and assets required to enroll, verify, approve, log out, and avoid redirect loops;
- protect every other back-office request when the employee is subject to MFA;
- require step-up for install, uninstall, reset, delete, enable/disable, upgrade, import, and bulk equivalents;
- derive legacy actions from normalized controller/action values, not a caller-supplied route name;
- continue to use the existing safe-return-target validation.

Acceptance criteria:

- The same policy vectors pass for Symfony routes and equivalent legacy controller/action pairs.
- Switching entry points cannot change an allow/verify/step-up decision.

## 3. Legacy back-office enforcement bridge

- Validate the earliest supported PS 1.7 hook that runs after employee context is available but before controller business logic. Start with `actionDispatcherBefore`; if its timing is insufficient on a supported minor, use the earliest compatible admin-controller initialization hook.
- Register that hook from `mpadmin2fa.php` and delegate immediately to a small legacy adapter.
- The adapter must ignore front-office and CLI requests, read the authenticated employee from `Context`, normalize the requested controller/action, ask `AdminMfaAccessPolicy` for a decision, and redirect/terminate before the target controller action runs.
- Keep `actionAdminControllerSetMedia` presentation-only. JavaScript must not be part of the security boundary.
- Audit-log relevant denials and redirects without secrets, OTPs, cookies, or recovery codes.

Required cases include direct `index.php?controller=AdminModules` requests and all install, uninstall, reset, delete, enable/disable, upgrade, and bulk variants supported by PS 1.7.

Acceptance criteria:

- No protected legacy controller reaches `postProcess()` before MFA succeeds.
- No sensitive legacy mutation executes without a current step-up grant.
- Enforcement remains effective with JavaScript disabled.

## 4. Atomic failure counting

Replace the read/increment/write sequence in `src/Security/RateLimiter.php` and `src/Repository/SecurityRepository.php` with one repository operation that atomically increments and returns the effective count.

Implementation requirements:

- Use `UPDATE failures = failures + 1` for an existing row.
- For a missing row, insert-if-absent and retry the increment when another request wins the insert race.
- Update `last_failure_at` in the same operation as the increment.
- Base delay, lock, and alert decisions on the returned database count.
- Keep success/reset idempotent and scoped to the same employee and limiter purpose.
- Do not use process-local locks; PHP workers and containers do not share them.

Tests must cover sequential thresholds, simultaneous failures, simultaneous first failures with no existing row, and the documented ordering of a reset racing with a failure.

Acceptance criteria:

- The stored count equals the number of committed failures.
- No concurrent request weakens the resulting delay or alert threshold.
- SQL works across the MySQL/MariaDB range supported by this branch.

## 5. PS 1.7 tab and permission lifecycle

PS 1.7 does not call this module's `postInstall()`, and its module manager can dispatch tab registration after `install()` returns `false`. The module must own this lifecycle instead.

- Add idempotent helpers to install/update tabs, grant profile access after tabs exist, remove tabs/access, and roll back partial work.
- At the start of `install()`, retain the declared tab metadata locally and suppress PS 1.7's later automatic tab registration for that module instance.
- Use this controlled order:
  1. validate packaged production dependencies;
  2. run the parent module installation;
  3. install schema and configuration;
  4. register hooks;
  5. create/update tabs explicitly;
  6. grant profile access;
  7. return success only after all stages complete.
- On every failure, roll back tabs/access, hooks, configuration, schema, and parent installation as applicable. Keep tabs suppressed so the PS 1.7 install event cannot recreate them.
- Keep `postInstall()` only as an idempotent compatibility wrapper if useful; correctness cannot depend on it.
- Reuse the same reconciliation helper from upgrades so clean installs and upgraded installs converge.
- Make uninstall tolerate missing and partially created resources.

Tests must inject failures before parent install and after schema, hook, and tab stages. They must also cover clean install, repeated reconciliation, every supported upgrade origin, normal uninstall, and uninstall after partial repair.

Acceptance criteria:

- Clean install and upgrade produce identical tabs and access rows.
- An eligible non-SuperAdmin can reach the authenticator navigation after a clean install.
- Every failed install leaves zero module tabs and zero orphan access rows.
- Install, reconciliation, upgrade, and uninstall are idempotent.

## 6. SuperAdmin approval enforcement

Centralize approval authorization in a service and require all of:

- an authenticated employee;
- native `update` permission for the module resource;
- `id_profile === (int) _PS_ADMIN_PROFILE_`;
- approver employee ID different from the enrollment owner ID.

Apply the check to every approval entry point. Log denied attempts with actor, target, and reason but no secrets. Keep documentation and form help aligned with the enforced policy.

Tests must prove that another SuperAdmin succeeds, self-approval fails, a non-SuperAdmin with delegated `update` permission fails, and missing native permission fails.

## 7. Verification and CI

- Run unit tests on PHP 7.1 and 7.4.
- Run the full PHP syntax scan on PHP 7.1 and strict Composer validation.
- Against a disposable database, test clean install, failure rollback, upgrade, reconciliation, and uninstall.
- Exercise both Symfony and legacy requests with JavaScript disabled.
- Compile the service container and enumerate module routes and console commands.
- Build the release archive from a clean checkout and install that archive.
- Add a PS 1.7 CI job that installs the packaged module and runs lifecycle and request smoke tests.
- Run the concurrency test repeatedly with a deterministic final count.
- Retain sanitized diagnostics on CI failure.

## Definition of done

- Every PS 1.7 audit finding has a regression test.
- All tests pass on every supported PHP and PS 1.7 target.
- No PrestaShop core modification is needed.
- The release archive installs, upgrades, and uninstalls without residual module-owned tabs, access rows, configuration, hooks, or schema.
- Security documentation matches the implemented behavior.
