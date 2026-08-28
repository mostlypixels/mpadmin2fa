# How Admin 2FA works on PrestaShop 1.7.8

## The short version

The module adds a **second check** after an employee enters the correct password.

| Term | Plain meaning |
| --- | --- |
| **2FA** | Sign-in needs a password and a second proof. |
| **Authenticator code** | A six-digit code from an app on the employee's phone. |
| **Recovery code** | A one-use backup code for a lost or unavailable phone. |
| **Fresh check** | Another authenticator code before a sensitive change. |

The default policy requires 2FA for **SuperAdmin** employees. An administrator can also require it for everyone or for selected profiles.

## What happens during sign-in

1. The employee enters their email and password.
2. PrestaShop checks the password.
3. The module checks whether this employee needs 2FA.
4. The module asks for setup or for an authenticator code.
5. A correct code opens the requested back-office page.

**Use HTTPS for every back-office sign-in.**

The module rejects an authenticator code that was already used. It also slows repeated failed attempts and records security events.

## Set up an authenticator

1. Open **Admin 2FA > Your authenticator**.
2. Select the setup action.
3. Wait for approval if the page asks for it.
4. Scan the QR code with an authenticator app.
5. Enter the six-digit code from the app.
6. Save the recovery codes somewhere safe.
7. Confirm that you saved them.

**Recovery codes appear only once.** The database stores protected hashes, not readable copies.

A SuperAdmin cannot approve their own request. The first SuperAdmin can enroll without approval when no active SuperAdmin factor exists.

## Sign in without the phone

1. Select **Use a recovery code** on the challenge page.
2. Enter one unused recovery code.
3. Set up a new authenticator immediately.
4. Save the new recovery codes.

The employee cannot browse the rest of the back office until the new setup is complete. A recovery code stops working after one use.

## Why the module asks again

The module asks for a **fresh authenticator code** before a sensitive action:

- changing the 2FA policy;
- approving an enrollment;
- resetting another employee's factor;
- installing, changing, or removing a module;
- importing or enabling a theme.

The fresh check normally remains valid for **300 seconds**. An administrator can change this period.

## Where administrators work

| Page | Use it to... |
| --- | --- |
| **Your authenticator** | Set up, replace, or manage your own factor. |
| **Employee 2FA** | See which employees are enrolled. |
| **Pending approvals** | Approve eligible setup requests. |
| **Security** | Choose who needs 2FA and where alerts go. |
| **Activity log** | Review successful and failed security events. |

An employee cannot turn off 2FA when shop policy requires it.

## Maintenance commands

| Command | What it does |
| --- | --- |
| `mpadmin2fa:key:health` | Checks the protected encryption key. |
| `mpadmin2fa:key:rotate prepare` | Starts a cookie-key change. |
| `mpadmin2fa:key:rotate commit` | Finishes a cookie-key change. |
| `mpadmin2fa:audit:prune` | Deletes old activity records. |
| `mpadmin2fa:factor:reset <email>` | Resets one employee's factor. |

**Do not improvise during key rotation.** Read the command output and complete both phases in order.
