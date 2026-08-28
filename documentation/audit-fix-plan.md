# Audit remediation plan — PrestaShop 8

Target branch: `2.x-ps8`

All fixes in this plan must be implemented inside `mpadmin2fa`; no PrestaShop core patch is required.

## Objectives

- Enforce MFA and step-up checks on Symfony and remaining legacy back-office requests.
- Count failed attempts correctly under concurrency.
- Enforce the documented rule that another SuperAdmin approves enrollment.
- Preserve the native PS 8 install lifecycle and cover it with integration tests.

## Delivery order

1. Add characterization tests and an integration-test harness.
2. Extract a shared access-policy service.
3. Gate remaining legacy back-office requests server-side.
4. Make rate-limit increments atomic.
5. Enforce SuperAdmin approval.
6. Expand lifecycle, compatibility, and release-package verification.

## 1. Test scaffolding and characterization

- Keep the existing unit suite, and add database-backed integration tests that boot the module in disposable PS 8 installations.
- Add helpers for employees, profiles, sessions, tabs, access rows, and rate-limit rows.
- First capture the failing behavior:
  - an enrolled but unverified employee can reach a remaining legacy admin controller;
  - a sensitive legacy action can avoid step-up;
  - concurrent failures produce a count lower than the submitted attempts;
  - a non-SuperAdmin with delegated `update` permission can approve enrollment.
- Add lifecycle baselines proving that a clean install invokes the supported post-install behavior and that failed installation/uninstall leave no module-owned state.

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

- Existing Symfony-route tests continue to pass.
- The same policy vectors pass for modern route names and equivalent legacy controller/action pairs.
- Switching entry points cannot change an allow/verify/step-up decision.

## 3. Legacy back-office enforcement bridge

- Inventory the legacy controllers and actions remaining in every supported PS 8 minor version.
- Validate the earliest supported hook that runs after employee context is available but before controller business logic. Start with `actionDispatcherBefore`; if its timing is insufficient, use the earliest compatible admin-controller initialization hook.
- Register that hook from `mpadmin2fa.php` and delegate immediately to a small legacy adapter.
- The adapter must ignore front-office and CLI requests, read the authenticated employee from `Context`, normalize the requested controller/action, ask `AdminMfaAccessPolicy` for a decision, and redirect/terminate before the target action runs.
- Keep `actionAdminControllerSetMedia` presentation-only. JavaScript must not be part of the security boundary.
- Audit-log relevant denials and redirects without secrets, OTPs, cookies, or recovery codes.

Required tests include direct legacy-controller URLs on each supported minor, any module-management action still exposed through them, access after password login but before MFA, redirect-loop prevention, and confirmation that modern Symfony module-management routes retain step-up protection.

Acceptance criteria:

- No protected legacy controller reaches its business action before MFA succeeds.
- No sensitive legacy mutation executes without a current step-up grant.
- Enforcement remains effective with JavaScript disabled.
- The bridge does not alter fully migrated Symfony routes except through the shared policy.

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
- SQL works across the MySQL/MariaDB range supported by PS 8.

## 5. SuperAdmin approval enforcement

Centralize approval authorization in a service and require all of:

- an authenticated employee;
- native `update` permission for the module resource;
- `id_profile === (int) _PS_ADMIN_PROFILE_`;
- approver employee ID different from the enrollment owner ID.

Apply the check to every approval entry point. Log denied attempts with actor, target, and reason but no secrets. Keep documentation and form help aligned with the enforced policy.

Tests must prove that another SuperAdmin succeeds, self-approval fails, a non-SuperAdmin with delegated `update` permission fails, and missing native permission fails.

Acceptance criteria:

- Delegating the module's native permission cannot delegate SuperAdmin-only approval.
- No employee can approve their own enrollment.

## 6. Preserve and verify the PS 8 lifecycle

PrestaShop 8 supports the module's post-install lifecycle. Keep that native path; do not copy the PS 1.7 tab-registration workaround unless a supported PS 8 minor is independently shown to need it.

- Keep `postInstall()` as the normal access-reconciliation entry point.
- Make tab/access reconciliation idempotent so repair and upgrade paths can reuse it.
- Make cleanup tolerate partial installation state without hiding the original error.
- Test clean install, repeated reconciliation, every supported upgrade origin, injected failures after schema/configuration/hook/tab stages, normal uninstall, and uninstall after partial repair.
- Ensure the release package contains production dependencies and excludes development-only dependencies.

Acceptance criteria:

- Clean install and upgrade converge on the same tabs and permissions.
- Failed installation and uninstall leave no module-owned schema, configuration, hooks, tabs, or access rows.
- Native PS 8 tab registration runs exactly once.

## 7. Verification and CI

- Run unit tests on PHP 7.2 and 8.1, plus every additional runtime officially supported by the branch.
- Run the full PHP syntax scan on PHP 7.2 and strict Composer validation.
- Against disposable databases for each supported PS 8 minor, test clean install, failure rollback, upgrade, reconciliation, and uninstall.
- Exercise Symfony and legacy requests with JavaScript disabled.
- Compile the service container and enumerate module routes and console commands.
- Build the release archive from a clean checkout and install that archive.
- Add PS 8 CI jobs that install the packaged module and run lifecycle and request smoke tests.
- Run the concurrency test repeatedly with a deterministic final count.
- Retain sanitized diagnostics on CI failure.

## Definition of done

- Every PS 8 audit finding has a regression test.
- All tests pass on every supported PHP and PS 8 target.
- No PrestaShop core modification is needed.
- The release archive installs, upgrades, and uninstalls without residual module-owned state.
- Security documentation matches the implemented behavior.
