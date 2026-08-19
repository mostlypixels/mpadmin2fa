# Admin 2FA — Spec

## Problem

A PrestaShop back office is protected by a password alone. A leaked or reused employee
password gives an attacker the full admin: orders, customer data, payment configuration.
`mpadmin2fa` adds a second factor to back-office authentication, with the merchant deciding
which profiles must use it.

## Scope

- **In scope:** employee (back-office) authentication only — TOTP apps and emailed codes,
  per-profile enforcement, self-service enrolment, recovery codes, a CLI reset command.
- **Out of scope:** customer/front-office accounts, Web Service and Admin API credentials,
  SSO/OAuth, WebAuthn/hardware keys, IP allow-listing, password policy.

## Behaviour

| #  | Given | When | Then |
|----|-------|------|------|
| 1  | An employee with no 2FA configured, in a profile where 2FA is enforced | They sign in with a correct password | They are redirected to enrolment and cannot reach any other admin page until they finish |
| 2  | An employee with no 2FA configured, in a profile where it is not enforced | They sign in with a correct password | They reach the dashboard; a dismissible notice offers enrolment |
| 3  | An employee enrolling in TOTP | They scan the QR code and submit a valid code | The secret is confirmed, ten recovery codes are shown once, and 2FA is active |
| 4  | An employee enrolling in email codes | They request and submit a valid emailed code | Email is confirmed as their method, recovery codes are shown once, and 2FA is active |
| 5  | An employee with 2FA active | They sign in with a correct password | The session is held unverified and every admin request redirects to the challenge page until a valid second factor is submitted |
| 6  | An employee at the challenge page | They submit a valid TOTP or emailed code | The session is marked verified and they land on their intended page |
| 7  | An employee at the challenge page | They submit an invalid code | The attempt is counted, an error is shown, and the session stays unverified |
| 8  | An employee who fails the challenge more than the configured attempt limit | They submit again | Further attempts are refused for the configured lockout window and the failure is logged |
| 9  | An employee who cannot use their factor | They submit a recovery code at the challenge page | The session is verified, that code is consumed and cannot be reused |
| 10 | An employee with 2FA active | They sign out | The verified mark is cleared; the next sign-in challenges again |
| 11 | A merchant on the module configuration page | They enforce 2FA for a profile | Every employee in that profile follows row 1 on their next sign-in |
| 12 | An employee locked out with no recovery codes left | The merchant runs the CLI reset command for their email | Their 2FA is disabled and the next sign-in follows row 1 or 2 |
| 13 | An employee with 2FA active | They authenticate through the "stay logged in" cookie | They are challenged before reaching any admin page |

## Hooks

The challenge is enforced through the admin Symfony container, not through legacy hooks:
services in `config/admin/services.yml` are loaded into the admin kernel by
`LoadServicesFromModulesPass`, so an event subscriber can gate requests behind the firewall.

| Integration point | Why |
|-------------------|-----|
| `kernel.request` subscriber (admin container) | Redirect an authenticated but unverified session to the challenge, allowing only the challenge, enrolment and logout routes |
| `Symfony\Component\Security\Http\Event\LoginSuccessEvent` | Mark a freshly authenticated session unverified, covering form login and remember-me alike |
| `displayAdminLogin` | Render the module's notice on the login page |
| `actionAdminControllerSetMedia` | Load the enrolment page assets |

## Data

Tables (prefixed, InnoDB, created in `install()` and dropped in `uninstall()`):

| Table | Holds |
|-------|-------|
| `mp2fa_employee` | `id_employee` (PK), `method` (`totp`\|`email`), `totp_secret` (encrypted), `confirmed_at`, `date_add`, `date_upd` |
| `mp2fa_recovery_code` | `id_employee`, `code_hash`, `used_at` — ten rows per enrolled employee |
| `mp2fa_email_code` | `id_employee`, `code_hash`, `expires_at`, `used_at` — pending emailed codes |
| `mp2fa_attempt` | `id_employee`, `ip`, `succeeded`, `date_add` — feeds rate limiting and the audit trail |

Secrets and codes are never stored in clear: TOTP secrets are encrypted, recovery and email
codes are hashed with `password_hash()`.

Configuration keys: `MP2FA_ENFORCED_PROFILES` (CSV of profile ids), `MP2FA_ALLOWED_METHODS`,
`MP2FA_EMAIL_CODE_TTL` (default 600s), `MP2FA_MAX_ATTEMPTS` (default 5),
`MP2FA_LOCKOUT_SECONDS` (default 900).

## Configuration page

| Setting | Default |
|---------|---------|
| Profiles that must use 2FA | none |
| Methods employees may choose | TOTP and email |
| Emailed code lifetime | 10 minutes |
| Failed attempts before lockout | 5 |
| Lockout duration | 15 minutes |
| Enrolment status per employee (read-only list, with a per-employee reset action) | — |

## Acceptance criteria

- [ ] Installs and uninstalls cleanly on PrestaShop 9.x — uninstall drops every table and configuration key
- [ ] A password alone never reaches an admin page for an employee with 2FA active (rows 5, 13)
- [ ] A TOTP code from a standard authenticator app validates, and is refused outside the ±1 step window
- [ ] An emailed code is refused after its TTL and after a single successful use
- [ ] A recovery code works exactly once
- [ ] Attempts beyond the configured limit are refused for the lockout window
- [ ] The CLI reset command restores access for a named employee
- [ ] No secret, recovery code or emailed code is readable in the database
- [ ] Behat coverage for rows 5, 6, 7, 9 and 13

## Open questions

- Where does the TOTP encryption key come from — reuse `_COOKIE_KEY_`, or a module key generated at install and stored outside the database?
- Remember-me is configured with `signature_properties: ['password']`; confirm the `LoginSuccessEvent` subscriber actually fires on cookie-based re-authentication, or gate on the session flag alone.
- Should the challenge apply to non-interactive entry points that share the admin firewall, or only to browser sessions?
- Trusted-device support ("don't ask again on this browser for 30 days") — worth having, or does it undercut the point?
- Which TOTP library: `spomky-labs/otphp` (maintained, adds a dependency) or a ~50-line in-module implementation of RFC 6238?
