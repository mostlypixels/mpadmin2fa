# Architecture for PrestaShop 9

## One-minute overview

Admin 2FA sits **after the password check** and **before protected back-office access**.

```text
Password accepted -> 2FA policy checked -> setup or code requested -> access granted
```

Small security services make decisions. Controllers display pages. The repository reads and writes module data.

## Where to look

| If you need to change... | Start here |
| --- | --- |
| Installation, hooks, or menu tabs | `mpadmin2fa.php` |
| URLs | `config/routes.yml` |
| Service wiring | `config/admin/services.yml` |
| Sign-in and access rules | `src/EventSubscriber/AdminMfaSubscriber.php` |
| HTTPS sign-in protection | `src/Http/LoginHttpsGuard.php` |
| Pages and form actions | `src/Controller/Admin/MfaController.php` |
| Codes, policy, sessions, limits, or keys | `src/Security/` |
| Database access | `src/Repository/SecurityRepository.php` |
| Employee, approval, and activity lists | `src/Grid/` |
| Maintenance commands | `src/Command/` |
| Back-office HTML | `views/templates/` |

## Request flow

1. **PrestaShop** accepts the employee password.
2. The **login gate** clears old module session data.
3. The **policy service** decides whether the employee needs 2FA.
4. The employee goes to **setup**, a **code challenge**, or the requested page.
5. The **controller** checks the form and its CSRF token.
6. A **security service** checks the authenticator or recovery code.
7. The **repository** saves the result and writes an activity event.

## Version adapter

| Concern | This branch uses |
| --- | --- |
| **PHP syntax** | PHP 8.1 features, typed properties, promotion, and attributes |
| **Symfony** | 6.4 |
| **Login events** | `LoginSuccessEvent`, `LogoutEvent`, and `RequestEvent` |
| **Database API** | Current Doctrine DBAL result API |
| **Controller access rules** | PHP attributes |
| **Service setup** | Autowire and autoconfigure |

The HTTPS guard rejects insecure protected sign-ins and closes the new security token.

**Keep this adapter on this branch.** Do not copy an adapter from another PrestaShop line without compatibility tests.

## Stored data

The installer creates six tables. PrestaShop adds the configured table prefix.

| Table | What it stores |
| --- | --- |
| `mp2fa_keyring` | Protected encryption keys and their versions. |
| `mp2fa_employee` | Enrollment state and the encrypted authenticator secret. |
| `mp2fa_recovery_code` | Hashed recovery codes and use dates. |
| `mp2fa_approval` | Setup requests and decisions. |
| `mp2fa_rate_limit` | Failed-attempt counts and temporary blocks. |
| `mp2fa_audit` | Security events and useful investigation data. |

Uninstalling the module removes these tables.

## How secrets are protected

1. The installer creates a random **data key**.
2. `_NEW_COOKIE_KEY_` protects that data key.
3. The data key encrypts each authenticator secret.
4. Key versions allow a controlled key change.

Authenticator secrets are not stored as plain text after setup. Recovery codes are stored as password hashes and shown only once.

## Failure and access rules

- A broken protected key **blocks protected access** instead of bypassing 2FA.
- Repeated failures cause a temporary block and can send an alert.
- Sensitive POST actions need both **PrestaShop permission** and a valid **CSRF token**.
- Policy changes, approvals, and resets also need a **fresh authenticator code**.
- Employees cannot approve or reset themselves from the employee list.

## Dependencies and releases

The release build moves third-party code into a private namespace. This prevents conflicts with PrestaShop and other modules.

| Dependency | Release line |
| --- | --- |
| Google2FA | 9 |
| BaconQrCode | 3 |
| Defuse PHP Encryption | 2.4 |

The module runs on PHP 8.1 through 8.5. The build excludes documentation, tests, tools, and the normal Composer vendor directory.
