# PS8 real-request checkpoint — 2026-09-04

## Scope and findings

The installed scoped package is exercised through PrestaShop's unchanged admin front controller over real Apache TLS. The HTTP client submits the native password form, retains native cookies, parses the actual MFA CSRF field and submits an independently generated RFC 6238 code. No production authentication bypass or test-only route is added.

The request tests exposed PS8-specific gaps not exercised by the lifecycle suite:

- Modern MFA enforcement ran before PS8 restored its native employee authentication token. It now runs after native authentication and URL-token validation, before controllers.
- The legacy dispatcher needs an authenticated Symfony token for tokenized MFA redirects, and a session that remains available after the Symfony request has been removed from its stack.
- PS8's native password login does not emit Symfony's interactive-login event. A native post-login hook now resets MFA state and rejects insecure login. The hook is registered on both install and upgrade.
- Insecure-login rejection must clear the native employee cookie as well as the Symfony session.
- PS8 uses native dashboard/login/logout links instead of the newer Symfony login/logout routes.
- PS8 has no `admin_homepage` route. Removing that redirect from the module's native `AdminSecurity` annotations restores PS8's normal access-denied response instead of turning denials into HTTP 500 errors.
- Enrollment approval requires a SuperAdmin actor, native read/update permission, valid CSRF, and fresh MFA. The first SuperAdmin can bootstrap enrollment; later SuperAdmins wait for approval before a factor secret is created.

Module hook service lookups explicitly use the booted admin kernel. The surrounding PrestaShop checkout is not changed.

## Verification

On PS 8.2.8 / PHP 8.1.33 / MySQL 5.7.44, using an isolated disposable shop and the rebuilt scoped package:

- Unit suite: 110 tests, 536 assertions; two opt-in database tests skipped.
- 64 real HTTPS request checks passed. Coverage includes native password login, modern/legacy and AJAX MFA gating, CSRF and TOTP replay protection, expired step-up, recovery/replacement restrictions, SuperAdmin approval, native profile permissions, denial auditing, and delegated-profile rejection.
- Database suite: 2 tests, 30 assertions.
- The combined lifecycle/request run passed clean install, repeated-install protection, simulated 0.2.7-to-0.2.8 upgrade, requests, database counting, uninstall, and rollback/reinstallation at the schema, configuration, hook, tab, and access failure stages.
- GitHub Actions run 33859274576 passed all six jobs on commit `64b74a5`, including the packaged lifecycle matrix on PS 8.0.0 / PHP 7.2 and PS 8.2.8 / PHP 8.1.

## CI credential hygiene

GitGuardian flagged the former fixed administrator credentials in the lifecycle workflow. They were test-only credentials for a fresh localhost shop, not production credentials, but keeping reusable password literals in source creates noisy alerts and encourages reuse.

The PS8 workflow now creates a unique test email and random password for every lifecycle job, masks the password before any later step runs, and passes both values to the installer and request harness through environment variables. A cross-version scan on 2026-09-04 found the old fixture still present on PS9 and PS1.7; those branches must be remediated in their own tasks. Rotation is only required if the old value was reused outside disposable test shops. After a green CI run with this change, the GitGuardian finding can be resolved as a test credential.

## Running

The current request runner also executes browser checks; install the browser dependencies described in the [browser checkpoint](ps8-browser-upgrade-verification.md#running) first. The lifecycle harness needs Apache, the matching Apache PHP module, OpenSSL, cURL and setsid. Build both back-office themes as well as Classic: the native admin header requires the generated `public/preload.tpl` files, even for HTTP-only tests. It starts its own non-privileged server on loopback ports 8443 and 8080, uses a newly generated test certificate with certificate verification enabled, and stops that server on exit. Runtime logs and disposable cookies remain in a private temporary directory for diagnosis.

Use the lifecycle command only against an explicitly disposable installed shop. To rerun just the request slice, install the built package first and supply that shop's disposable administrator credentials:

~~~sh
MP2FA_INTEGRATION=1 \
MP2FA_PS_ROOT=/absolute/path/to/disposable/shop \
MP2FA_TEST_EMAIL=mfa-test@example.test \
MP2FA_TEST_PASSWORD=generated-disposable-password \
  bash tests/Integration/run_requests.sh
~~~

The request fixture resolves the disposable administrator by `MP2FA_TEST_EMAIL`, reuses its password hash for additional employee fixtures, and submits `MP2FA_TEST_PASSWORD` for every native login. It replaces module-owned MFA state and changes SSL configuration. Never point it at a real shop or supply operator credentials.

## Browser and historical upgrade follow-up

The browser interaction and genuine historical-source package upgrade work is tracked in [ps8-browser-upgrade-verification.md](ps8-browser-upgrade-verification.md). The HTTP-only results above remain the earlier checkpoint. PS9 and PS1.7 are separate work items.
