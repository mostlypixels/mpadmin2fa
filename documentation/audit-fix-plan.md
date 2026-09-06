# Audit remediation plan — PrestaShop 1.7

Target branch: `1.x-ps17`

All fixes in this plan must be implemented inside `mpadmin2fa`; no PrestaShop core patch is required.

## Beta-RC verification closeout — 2026-09-06

Verdict: **the PS 1.7 beta-RC matrix is green** at implementation commit
deaeeb57b588cff4bf1e940726f18a292f7e1f17. This supersedes the historical
verification gaps below.

[Full eight-job CI run](https://github.com/mostlypixels/mpadmin2fa/actions/runs/34047874110)
passed. The original three local commits were reviewed and pushed after merging
the independent remote counter fix; the
[initial delivery run](https://github.com/mostlypixels/mpadmin2fa/actions/runs/34031608125)
also passed. This audit is committed separately after the expanded evidence.

### Matrix and evidence

| PrestaShop | PHP | Unit tests | Package integration |
|---|---|---|---|
| 1.7.8.0 | 7.1 | 99 tests / 383 assertions | Passed |
| 1.7.8.0 | 7.4 | Covered by package integration | Passed |
| 1.7.8.11 | 7.1 | 99 tests / 383 assertions | Passed |
| 1.7.8.11 | 7.4 | 99 tests / 383 assertions | Passed |

Each of the four package jobs passed:

- **109 real HTTP checks without JavaScript:** native password login, first-SuperAdmin
  enrollment, challenge CSRF, invalid/valid TOTP, replay, logout, fresh-login
  invalidation, HTTPS rejection, Symfony and legacy administration, redirect-loop
  prevention, approval permissions, and recovery/replacement restrictions.
- **7 database/controller tests / 194 assertions, repeated ten times:** ten independent
  workers for both absent and existing counter rows; every returned count, final
  count, exact UTC delay and cap, reset ordering and subject isolation. Approval
  cases cover self-approval, delegated profiles, missing native update permission,
  second-SuperAdmin approval, target state and exact approval/denial audit metadata.
- **29 alert checks:** captured transport verifies thresholds, blocked attempts,
  reset behavior and transport failure without sending test mail externally.
- **Lifecycle:** clean install, duplicate-install preservation, repeated reconciliation,
  missing table/configuration/hook/tab/access repair, and partial-state uninstall.
  Verification includes six tables, seven tabs, seven configuration keys, seven
  hooks, the active key, ordinary-profile navigation, routes, subscriber registration
  and four console commands.
- **Five installer failure stages:** before parent installation and after schema,
  configuration, hooks and tabs. Each restores 19 pre-install state comparisons
  covering employee/access, module, hook, profile and tab tables, module configuration
  and schema. Each failure permits immediate reinstall and uninstall.
- **Historical 0.2.7 upgrade:** upgrade to 0.2.8, recovery from failure after schema
  migration, repeated upgrade/backfill repair, preserved enrolled secrets, recovery
  codes, keys, audit/approval/rate-limit history and policy, and final cleanup.
  All 17 structure comparisons with a clean installation pass.

Module-action requests cover modern single/bulk operations and legacy GET flags,
POST actions and bulk flags for install, uninstall, reset, delete, enable/disable,
upgrade and import, plus mobile toggles and update-all where routed by PrestaShop.
Expired step-up requests redirect to challenge before module records change.

### Fixes exposed by integration testing

- Explicitly register the Symfony subscriber after native employee-token restoration;
  skip the legacy bridge for already-routed Symfony requests to prevent MFA loops.
- Reset MFA on native password login, attach the legacy session, reject insecure
  authenticated login, and use PS 1.7 native login/logout/dashboard/configuration APIs.
- Prevent controller casing or benign action fields from hiding sensitive operations.
- Parse stored failure and blocking timestamps as UTC.
- Preserve duplicate installations, repair resources without replacing keys or policy,
  and remove module access roles even when their tab is missing.

Workers initially raced PrestaShop's unrelated translation-cache bootstrap. They now
load the installed package repository and connect before their shared start barrier.
Twenty local repetitions passed, followed by ten repetitions on every CI endpoint.

### Package and credential evidence

Every lifecycle/request job downloads the artifact from tools/build-scoped.php and
checks SHA256SUMS before installation. Runtime reflection verifies that production
classes come from that package. PHP 7.1 syntax, Composer metadata and scoped-package
checks remain enabled. Source checkout, harness and agent files are excluded.
Dependency CI metadata is removed before checksumming; this fixed the first expanded
run's upload/checksum mismatch. The CI artifact was downloaded and verified locally.

CI generates and masks a fresh random administrator password per run. The previous
literal appeared only in test/local-development/default examples within inspected
module history and accessible PrestaShop worktrees, including stock installer/UI
fixtures and localhost Docker configuration. No production reuse was found within
that inspection; external systems were not available for verification. No history
rewrite or production rotation was performed. The GitGuardian dashboard alert was
not changed; the recorded workflow finding can be classified as a test credential.

### Beta-RC scope

The qualified upgrade origin is the genuine PS 1.7 version 0.2.7 tree at
96a0a0d15a1247d90825b800b9d8a41936d21383, built with its own production lockfile.
There were no published release assets. This beta-RC does not claim verified upgrades
from the earlier 0.1.0 experiment or unavailable 0.2.0–0.2.6 PS 1.7 packages.
The PS 8 and PS 9 branches were left untouched.

Cross-version browser automation remains optional and is not claimed here. Mandatory
JavaScript-disabled behavior is verified through real HTTP on every endpoint.
No surrounding PrestaShop source file was changed; its existing dirty state was preserved.

## Historical re-audit — 2026-08-30

Audited commit: `d9cbbdc` (`1.x-ps17`).

Verdict at that re-audit: **implementation substantially complete; verification incomplete**. No new remediation defect was reproduced, but several definition-of-done items still lack committed automated coverage.

| Area | Status | Re-audit result |
|---|---|---|
| Test scaffolding | Partial | 97 unit tests / 379 assertions pass on PHP 7.1 and 7.4. The lifecycle CI job uses a real 1.7.8.11 shop, but there is no reusable request test harness or coverage for every failure stage. |
| Shared MFA policy | Implemented, partially verified | Symfony and legacy entry points use `AdminMfaAccessPolicy`; unit vectors cover representative equivalent decisions. The full route/controller/action matrix is not exercised. |
| Legacy enforcement bridge | Implemented, not integration-tested | `actionDispatcherBefore` filters CLI/non-admin dispatches, invokes the legacy adapter before controller execution, sends the response, and exits. Direct `AdminModules` mutations and JavaScript-disabled requests are not tested end to end. |
| Atomic failure counting | Implemented and locally verified | A fresh 10-worker test returned all counts 1–10 and stored 10. The repository has no database integration test, so CI verifies only source shape and in-memory delegation. |
| PS 1.7 lifecycle | Implemented and locally verified | Clean install created 7 tabs, all 15 expected navigation read-access rows for 5 profiles, and the dispatcher hook. Two reconciliations succeeded. Injected tab failure failed install and left module row, tables, configuration, and tabs at 0. Normal uninstall also left module row, tables, roles, configuration, and tabs at 0. |
| SuperAdmin approval | Implemented, unit-tested | The authorizer rejects missing permission, non-SuperAdmin profiles, and self-approval; the controller audits denials. Controller/database integration coverage is still absent. |
| Verification and packaging | Partial | PHP 7.1 syntax scan, PHP 7.1/7.4 unit tests, strict Composer validation, installed container loading, 18 routes, and 4 commands were verified. CI builds and installs the package on 1.7.8.11, but not on the earliest target and not through the full failure/upgrade/request matrix. |

### Remaining delivery order after re-audit

1. Commit a database-backed concurrency test and run it repeatedly in CI.
2. Add live legacy/Symfony request tests for unverified MFA, step-up mutations, redirect-loop prevention, and JavaScript-disabled operation.
3. Expand packaged lifecycle CI to the earliest supported 1.7.8 release, every upgrade origin, and failures after schema, configuration, hook, and tab stages.
4. Add controller/database integration coverage for SuperAdmin approval and denial audit events.

### Local verification note

Production route and command enumeration succeeded. A local `cache:clear` as `www-data` was blocked by pre-existing root-owned cache files in the disposable test worktree; this is a harness ownership issue, not a reproduced module failure. The module container still loaded and exposed all expected routes and commands.

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
