# PS8 real-request checkpoint — 2026-09-02

## Scope and findings

The installed scoped package is exercised through PrestaShop's unchanged admin front controller over real Apache TLS. The HTTP client submits the native password form, retains native cookies, parses the actual MFA CSRF field and submits an independently generated RFC 6238 code. No production authentication bypass or test-only route is added.

The request tests exposed PS8-specific gaps not exercised by the lifecycle suite:

- Modern MFA enforcement ran before PS8 restored its native employee authentication token. It now runs after native authentication and URL-token validation, before controllers.
- The legacy dispatcher needs an authenticated Symfony token for tokenized MFA redirects, and a session that remains available after the Symfony request has been removed from its stack.
- PS8's native password login does not emit Symfony's interactive-login event. A native post-login hook now resets MFA state and rejects insecure login. The hook is registered on both install and upgrade.
- Insecure-login rejection must clear the native employee cookie as well as the Symfony session.
- PS8 uses native dashboard/login/logout links instead of the newer Symfony login/logout routes.

Module hook service lookups explicitly use the booted admin kernel. The surrounding PrestaShop checkout is not changed.

## Local verification

On PS 8.2.8 / PHP 8.1.33 / MySQL 5.7.44, using a disposable shop and the rebuilt scoped package:

- Unit suite: 108 tests, 519 assertions; two opt-in database tests skipped.
- 18 real-request checks passed: HTTPS password login; modern and legacy read/AJAX gating; challenge rendering and CSRF; invalid-CSRF rejection without code consumption; successful TOTP and native dashboard redirect; modern and legacy access after verification; TOTP replay rejection; fresh-login invalidation on both paths; logout before MFA; insecure AJAX login rejection and removal of its native authenticated session.
- The combined lifecycle/request run passed: clean install, repeated-install protection, simulated 0.2.7-to-0.2.8 upgrade (including restoration of both authentication hooks), requests, database counting (2 tests / 30 assertions), uninstall, and rollback/reinstallation at all five injected failure stages.
- CI now runs the same combined harness on PS 8.0.0 / PHP 7.2 and PS 8.2.8 / PHP 8.1. Remote results for this checkpoint must be checked separately.

## Running

The lifecycle harness now also needs Apache, the matching Apache PHP module, OpenSSL, cURL and setsid. It starts its own non-privileged server on loopback ports 8443 and 8080, uses a newly generated test certificate with certificate verification enabled, and stops that server on exit. Runtime logs and disposable cookies remain in a private temporary directory for diagnosis.

Use the existing lifecycle command only against an explicitly disposable installed shop. To rerun just the request slice, install the built package first:

~~~sh
MP2FA_INTEGRATION=1 MP2FA_PS_ROOT=/absolute/path/to/disposable/shop \
  bash tests/Integration/run_requests.sh
~~~

The request fixture replaces the disposable demo employee's factor and changes SSL configuration. Never point it at a real shop. It expects the documented CI demo account, not operator credentials.

## Remaining coverage

This is HTTP request coverage, not JavaScript or visual browser coverage. Still pending: expired step-up and sensitive mutations, recovery/replacement restrictions, the complete SuperAdmin approval/permission request matrix, browser interactions, and genuine historical-package upgrade coverage. PS9 and PS1.7 remain separate work items. User edits in the main audit file are preserved and excluded from this checkpoint.
