# Architecture for PrestaShop 8

## Purpose

Admin 2FA adds TOTP authentication to the PrestaShop back office.
The module does not replace the employee password.
It adds a second check after PrestaShop accepts the password.

This branch supports 8.0 through 8.2 and PHP 7.2.5 through 8.1.
The code does not use PHP syntax that is newer than PHP 7.2.

## Main parts

| Part | Location | Purpose |
| --- | --- | --- |
| Module entry point | `mpadmin2fa.php` | Installs tables, hooks, tabs, and default policy values. |
| Routes | `config/routes.yml` | Maps module URLs to controller actions. |
| Service definitions | `config/admin/services.yml` | Connects module classes to PrestaShop services. |
| Login gate | `src/EventSubscriber/AdminMfaSubscriber.php` | Applies enrollment, challenge, and step-up rules. |
| Controller | `src/Controller/Admin/MfaController.php` | Handles forms, grids, redirects, and administrator actions. |
| Security services | `src/Security/` | Checks TOTP codes, policy, sessions, rate limits, and keys. |
| Repository | `src/Repository/SecurityRepository.php` | Reads and changes module data. |
| Grids | `src/Grid/` | Shows employees, approvals, and audit events. |
| Commands | `src/Command/` | Supplies key, audit, and factor maintenance. |
| Templates | `views/templates/` | Shows the back-office user interface. |

## Request path

1. PrestaShop accepts the employee password.
2. The login subscriber creates clean module session data.
3. The request subscriber reads the active employee.
4. The policy service decides if the employee must use 2FA.
5. The subscriber selects enrollment, challenge, or normal access.
6. The controller validates the form and the CSRF token.
7. The security service validates the new TOTP counter or recovery code.
8. The repository changes the factor state and writes an audit event.
9. The session service records the successful check.
10. The controller sends the employee to a safe destination.

## Framework adapter

This branch uses Symfony 4.4.
The security adapter uses `InteractiveLoginEvent` and `RequestEvent`.
The repository uses the PrestaShop 8 Doctrine DBAL API.

PrestaShop 8 uses Symfony 4.4.
The subscriber uses the interactive-login event and the main-request API.
It reads the employee from the security token storage.
The controller uses PrestaShop 8 annotation access rules.
The service definitions adapt the shared design to the PrestaShop 8 container.

The key service includes a protected-key rewrapper.
This adapter reads a compatible legacy protected key and writes its current format.

Keep these framework differences in this branch.
Do not merge a framework adapter from another PrestaShop line without a compatibility test.

## Data model

The installer creates six tables with the configured PrestaShop prefix.

| Table | Stored data |
| --- | --- |
| `mp2fa_keyring` | Protected data keys and key versions. |
| `mp2fa_employee` | Factor state, encrypted TOTP secret, and last accepted counter. |
| `mp2fa_recovery_code` | Hashed recovery codes and use dates. |
| `mp2fa_approval` | Enrollment requests and approval decisions. |
| `mp2fa_rate_limit` | Failure counts and temporary block times. |
| `mp2fa_audit` | Security events, employee IDs, IP addresses, and metadata. |

PrestaShop supplies the actual table prefix.
The module removes these tables when an administrator uninstalls the module.

## Secret protection

1. The installer makes a random data key.
2. The installer protects the data key with `_NEW_COOKIE_KEY_`.
3. The key service encrypts each TOTP secret with the data key.
4. The employee table stores the ciphertext and the data-key version.
5. The keyring keeps old key versions during a controlled rotation.

The module does not store a TOTP secret as plain text after enrollment.
The module stores recovery codes as password hashes.
The module shows new recovery codes only in the employee session.

The release package scopes third-party namespaces.
This step prevents a Composer dependency conflict with PrestaShop or another module.
The runtime dependencies are Google2FA 8, BaconQrCode 2, and Defuse PHP Encryption 2.4.
The Composer PHP rule is `>=7.2.5`.

## Failure behavior

The module blocks access when it cannot validate the protected data key.
It returns a service-unavailable response for a protected request.
It also sends a security alert when alert delivery is available.

The rate limiter uses a hash of the employee and request source.
It does not store the raw rate-limit subject.
The audit table can contain an IP address.
Apply the configured retention period to this table.

## Access control

PrestaShop permissions control access to the module pages.
A fresh TOTP check protects policy changes, approvals, and factor resets.
POST actions also require a valid CSRF token.
The module rejects a self-approval and a self-reset from the employee list.

## Release boundary

Keep this `documentation/` directory in Git.
The scoped build excludes it before dependency installation.
The archive check rejects both `documentation/` and the old `docs/` path.
