# Audit remediation plan — PrestaShop 9

Target branch: `main`

All fixes in this plan must be implemented inside `mpadmin2fa`; no PrestaShop core patch is required. Changes in the surrounding `prestashop/prestashop` checkout are test-harness state and are outside this plan.

## Audit — 2026-08-30

Audited commit: `cf6b866` (`main`).

Current verdict: **incomplete; not ready for production**. The branch has a functioning Symfony MFA flow and a passing unit suite, but four security/lifecycle findings remain open and CI does not exercise an installed shop.

| Area | Status | Audit result |
|---|---|---|
| Test scaffolding | Partial | 72 unit tests / 317 assertions pass on PHP 8.1. There are no database, request, concurrency, controller-authorization, or lifecycle tests. |
| Shared MFA policy | Missing | `AdminMfaSubscriber` owns route allow/protect lists and decisions directly. There is no framework-neutral `AdminMfaAccessPolicy`, so equivalent entry points cannot be tested against one policy. |
| Legacy enforcement bridge | Missing | The module does not register `actionDispatcherBefore` and has no legacy adapter. `actionAdminControllerSetMedia` only adds JavaScript and is not a server-side security boundary. |
| Atomic failure counting | **Failed** | `RateLimiter` reads, increments in PHP, and writes an absolute value. A 40-worker installed-shop test returned repeated counts and stored only 16 failures for both subjects. |
| SuperAdmin approval | **Failed by inspection** | The endpoint requires native `update`, fresh MFA, POST/CSRF, and a different employee, but never requires the approver profile to equal `_PS_ADMIN_PROFILE_`. Delegated update permission can therefore delegate approval. |
| PS 9 lifecycle | **Unsafe by inspection** | Default access is deferred to `postInstall()`, but the normal PS 9 module-manager/CLI install path returns `onInstall()` and dispatches automatic tab registration without calling `postInstall()`. Automatic tab errors are outside the module's success boundary, and partial configuration is not explicitly rolled back. |
| Compatibility and packaging | Partial | Strict Composer validation, PHP 8.1 syntax, 18 routes, and 4 commands were verified. CI uses only PrestaShop 9.0.2 with PHP 8.1–8.4, runs unit tests, and builds the scoped package; it neither installs that package nor covers the documented 9.1/PHP 8.5 and future 9.2 rows. |

### Evidence notes

- The concurrency check used temporary, isolated rate-limit rows in the installed local shop and removed them afterward. Forty committed workers produced a highest returned count and final stored count of 16 instead of 40.
- The local checkout was PrestaShop 9.3.0-dev, beyond the module's declared `9.2.99` maximum. It was used for non-destructive loading checks only: all 18 routes and 4 commands loaded. No compatibility conclusion for 9.3 is claimed.
- Container lint was blocked by the surrounding test shop's Monolog configuration (`maxFiles` was a string instead of an integer). Route and command discovery still succeeded; this is test-harness state, not a reproduced module defect.
- A destructive clean install/uninstall cycle was intentionally not run against the existing PS9 shop. The lifecycle finding is established by the module and PS9 call paths: `ModuleManager::install()` calls `onInstall()` and dispatches the install event, while `postInstall()` is a separate API with no call from that path.

### Remediation order

1. Make failed-attempt increments atomic and add a repeatable database concurrency test.
2. Extract one shared MFA access policy and use it from Symfony and legacy adapters.
3. Register and integration-test a server-side legacy dispatcher bridge for every remaining legacy back-office path.
4. Enforce SuperAdmin-only approval in a centralized authorizer and test the controller boundary.
5. Move tabs/access into a module-owned, transactional install boundary with complete rollback.
6. Add installed-package lifecycle, request, compatibility, and upgrade jobs to CI.

## Remediation re-audit — 2026-09-01

Re-audited working tree: main, based on cf6b866, with the 0.2.8 remediation changes still uncommitted.

Current verdict: **the blocking code findings are remediated and the locally built package passes a destructive disposable lifecycle test. Final release confidence still depends on the new GitHub Actions matrix passing on its clean hosted runners; request-level modern/legacy browser coverage remains follow-up work.**

| Area | Current status | Re-audit result |
|---|---|---|
| Test scaffolding | Improved | 99 tests / 368 assertions pass on PHP 8.1; the two database tests are skipped in the ordinary unit run and pass separately with 30 assertions against an installed disposable shop. |
| Shared MFA policy | Implemented | AdminMfaAccessPolicy owns the normalized access decision and is used by both AdminMfaSubscriber and LegacyAdminMfaAdapter. Unit vectors and architecture tests cover the shared wiring. |
| Legacy enforcement bridge | Implemented | actionDispatcherBefore is registered during install/upgrade and delegates to a public, autowired legacy adapter before controller business logic. The installed service container, routes, and commands compile successfully. |
| Atomic failure counting | Implemented and database-tested | SecurityRepository::incrementFailure() uses one MySQL upsert with LAST_INSERT_ID() to return the effective committed count. Ten concurrent workers return exactly 1 through 10, and the stored count is 10. Reset-then-failure behavior is also covered. |
| SuperAdmin approval | Implemented | EnrollmentApprovalAuthorization requires native update permission, the SuperAdmin profile, a different actor/target, and the existing fresh-MFA and CSRF boundaries. Denials are recorded as enrollment.approval_denied. |
| PS 9 lifecycle | Implemented and destructively tested | Install now owns schema, configuration, hooks, tabs, and access. A disposable shop verified clean install, 0.2.7→0.2.8 migration, injected tab failure rollback, final reinstall, normal uninstall, and zero residual module state. |
| Compatibility and CI | Improved; hosted run pending | CI now covers PS 9.0.0/PHP 8.1, PS 9.0.3/PHP 8.4, PS 9.1.5/PHP 8.4 and PHP 8.5, plus a MySQL-backed lifecycle job on PS 9.0.2 using the locally built package. The workflow has been parsed locally but has not yet run on GitHub. |
| Release packaging | Fixed and lifecycle-tested | The disposable install found that scoping the module namespace, scoping legacy global classes, and omitting conventional vendor/autoload.php made the release unloadable. The scoper now preserves module/PrestaShop classes, writes a bridge to vendor-scoped, and fails the build if those rules regress. |

### Re-audit evidence

- Strict Composer validation passes.
- The scoped 0.2.8 release builds successfully, includes the upgrade script and SBOM/checksums, passes its TOTP smoke test, preserves module-owned namespaces, and exposes the conventional PrestaShop autoload path.
- The source suite passes with 99 tests, 368 assertions, and 2 expected database-test skips.
- The installed database suite passes with 2 tests and 30 assertions.
- A fresh isolated PS 9.3.0-dev test shop was used only as a lifecycle harness. The temporary package copy alone had its maximum adjusted from 9.2.99 to 9.3.99; no 9.3 compatibility claim is made and the source declaration remains unchanged.
- Clean package installation produced 6 module tables, 7 module tabs, the dispatcher hook, and the expected navigation access for every profile. All 18 routes and 4 console commands loaded after production cache compilation.
- The simulated 0.2.7 database upgraded to 0.2.8, replaced date_upd with last_failure_at, restored the dispatcher hook, and converged on the same tab/access state as a clean install.
- A database trigger deliberately rejected tab creation. Installation reported failure and rollback left no module record, tables, configuration, or tabs.
- A final clean install/uninstall left no module record, tables, tabs, authorization roles, or MP2FA_* configuration.
- The surrounding prestashop/prestashop checkout was mounted read-only for ordinary tests or copied into disposable Docker volumes for destructive tests. Its existing files were not edited.

### Remaining follow-up

- Run the new GitHub Actions workflow from a clean pushed branch and retain the job result as release evidence.
- Add request-level tests that execute equivalent modern and legacy admin requests, including JavaScript-disabled flows, instead of relying only on policy vectors, service compilation, and lifecycle checks.
- Add a final PS 9.2 CI row when the exact supported final tag is selected; do not infer 9.2 coverage solely from the current version constraint.

## Objectives

- Enforce MFA and step-up checks consistently on all PrestaShop 9 back-office entry points.
- Count failed attempts correctly under concurrent PHP workers.
- Enforce the documented rule that another SuperAdmin approves enrollment.
- Make clean install, repair, upgrade, failed install, and uninstall deterministic.
- Verify the actual release package across the declared PrestaShop/PHP matrix.

## 1. Test scaffolding and characterization

- Keep the existing unit suite and add database-backed integration tests that boot disposable PrestaShop 9 shops.
- Add helpers for employees, profiles, sessions, requests, tabs, access roles, configuration, hooks, and rate-limit rows.
- Capture each current failure before changing production code:
  - simultaneous failures lose increments;
  - a non-SuperAdmin with delegated module `update` permission can reach approval;
  - a remaining legacy admin request can bypass the Symfony subscriber;
  - CLI installation does not include `postInstall()` in its success result;
  - an automatic-tab failure can leave an installed module with incomplete navigation;
  - a late configuration failure can leave earlier configuration behind.
- Keep every test self-contained and remove only data created by that test.

Acceptance criteria:

- Every finding in this document has a failing characterization test followed by a passing regression test.
- Database tests run in CI rather than being skipped when no service is present.

## 2. Shared MFA access policy

Extract the decision logic and allow/protect inventories from `src/EventSubscriber/AdminMfaSubscriber.php` into a framework-neutral service such as `src/Security/AdminMfaAccessPolicy.php`.

The service should accept normalized employee, route/controller, action, HTTP method, verification state, recovery restriction, and time inputs. It should return an explicit decision: allow, require enrollment, require verification, require step-up, or deny. Redirects and framework response handling stay in adapters.

Both the Symfony subscriber and legacy adapter must use this service. The policy must:

- allow only the endpoints and assets needed to enroll, verify, recover, approve, and log out without loops;
- require MFA for every other back-office request when policy applies to the employee;
- require fresh step-up for install, uninstall, reset, delete, enable/disable, upgrade, import, theme changes, employee factor reset, approval, and policy changes;
- derive legacy actions from normalized controller/action values, not a caller-supplied route name;
- preserve safe return-target validation;
- fail closed when encryption-key validation fails.

Acceptance criteria:

- The same policy vectors pass for modern routes and equivalent legacy controller/action pairs.
- Switching entry points cannot change the decision for the same operation and session state.

## 3. Legacy back-office enforcement bridge

- Inventory remaining legacy controllers and dispatch paths on every supported PrestaShop 9 minor.
- Validate the earliest compatible hook after employee context exists and before controller business logic. Start with `actionDispatcherBefore`.
- Register the hook in `mpadmin2fa.php` and delegate immediately to a small legacy adapter.
- Ignore front-office and CLI dispatches, normalize controller/action/method, call the shared policy, and send/terminate on its response before the target mutation runs.
- Keep `actionAdminControllerSetMedia` presentation-only. Security must remain effective with JavaScript disabled.
- Audit relevant denials and redirects without OTPs, cookies, recovery codes, or encryption material.

Required tests include direct legacy-controller URLs, password-authenticated but MFA-unverified sessions, every remaining sensitive module action, redirect-loop prevention, and equivalent modern routes.

Acceptance criteria:

- No protected legacy controller reaches its business action before required MFA succeeds.
- No sensitive legacy mutation executes without a current step-up grant.

## 4. Atomic failure counting

Replace the read/increment/write sequence in `src/Security/RateLimiter.php` and `src/Repository/SecurityRepository.php` with one repository operation that atomically increments and returns the effective database count.

Implementation requirements:

- Increment existing rows in SQL instead of writing a caller-computed absolute value.
- Handle simultaneous first failures with insert-if-absent plus an atomic retry, or an equivalent database-safe upsert.
- Update the failure timestamp and blocking deadline consistently with the effective count.
- Base delay, lock, response, and alert decisions on the count returned by the database.
- Keep success/reset idempotent and scoped to exactly the same limiter subjects.
- Do not use process-local locks; workers and containers do not share them.

Tests must cover sequential thresholds, simultaneous existing-row failures, simultaneous first failures, employee and IP subjects, and the documented ordering of a reset racing with a failure.

Acceptance criteria:

- The stored count equals the number of committed failures.
- No concurrent request weakens the resulting delay or alert threshold.
- The SQL passes on every MySQL/MariaDB version supported by the declared PrestaShop 9 targets.

## 5. SuperAdmin approval enforcement

Centralize approval authorization in a service and require all of:

- an authenticated employee;
- native `update` permission for the module resource;
- `id_profile === (int) _PS_ADMIN_PROFILE_`;
- approver employee ID different from the enrollment owner ID;
- a current fresh-MFA grant.

Apply the check to every approval entry point and keep POST/CSRF enforcement. Log denied attempts with actor, target, and reason but no secrets. Align the page copy and operational documentation with the enforced rule.

Tests must prove that another SuperAdmin succeeds, self-approval fails, a non-SuperAdmin with delegated `update` permission fails, missing native permission fails, and stale step-up fails.

Acceptance criteria:

- Delegating the module's native permission cannot delegate SuperAdmin-only approval.
- No employee can approve their own enrollment.

## 6. Module-owned PS 9 lifecycle

`postInstall()` is not a safe correctness boundary. The PS9 command calls `ModuleManager::install()`, which returns the result of `onInstall()` and then dispatches tab registration; it does not call the separate `postInstall()` API. Event-driven tab failures also cannot change the already computed module result.

- Make tab creation/update and default access part of the module-owned `install()` success boundary, coordinating with or suppressing later automatic registration so work is not duplicated.
- Use one idempotent reconciliation helper from install, compatibility repair, and upgrades.
- Return `false` and roll back tabs/access roles, hooks, configuration, schema, and the native module row when any stage fails.
- Delete partially written configuration explicitly; do not assume `parent::uninstall()` owns module configuration.
- Keep `postInstall()` only as an idempotent compatibility/repair wrapper.
- Make uninstall tolerate missing or partially created resources while preserving the original error.

Tests must cover clean CLI and back-office installs, an ordinary profile created before install, repeated reconciliation, every supported upgrade origin, failures after parent/schema/configuration/hook/tab/access stages, normal uninstall, and uninstall after partial repair.

Acceptance criteria:

- Clean install and upgrade converge on the same tabs and access rows.
- Eligible ordinary profiles receive the intended navigation access during the install operation itself.
- Every failed install leaves zero module-owned schema, configuration, hooks, tabs, roles/access rows, and module records.
- Installation reports failure when tab/access initialization fails.

## 7. Verification, compatibility, and CI

- Keep unit tests on PHP 8.1–8.4 and add the documented PrestaShop 9.1/PHP 8.5 runtime row where build tooling permits runtime verification.
- Test the earliest and latest supported PrestaShop 9 minor; do not claim a future minor until its final release passes.
- Run strict Composer validation and a complete PHP 8.1 syntax scan.
- Install disposable database services and run concurrency, controller authorization, lifecycle, repair, upgrade, and uninstall tests.
- Exercise modern and remaining legacy requests with JavaScript disabled.
- Compile both service containers and enumerate module routes and console commands.
- Build the release archive from a clean checkout, inspect its contents, then install and exercise that exact archive.
- Retain sanitized diagnostics on CI failure.

## Definition of done

- Every PS9 audit finding has an automated regression test.
- All tests pass on every declared PHP and final PrestaShop 9 target.
- No PrestaShop core modification is required.
- The release archive installs, upgrades, repairs, and uninstalls without residual module-owned state.
- Security and compatibility documentation matches implemented and tested behavior.
