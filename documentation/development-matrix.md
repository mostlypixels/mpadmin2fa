# Compatibility matrix for PrestaShop 1.7.8

## Versions that must work

| Test | PrestaShop | PHP | Why it matters |
| --- | --- | --- | --- |
| **Lowest runtime** | 1.7.8 | 7.1 | Protects shops on the minimum PHP. |
| **Highest runtime** | 1.7.8 | 7.4 | Checks the newest supported PHP. |

This branch does **not** support PrestaShop 1.7.0 through 1.7.7. The Composer platform stays at **PHP 7.1**.

**Runtime and build PHP are different.** The shop can use PHP 7.1, but the ZIP builder needs PHP 8.1 through 8.4.

## Local Docker shop

| Item | Local value |
| --- | --- |
| **Compose file** | `docker-compose.mpadmin2fa.yml` |
| **PrestaShop** | PrestaShop 1.7.8.11 |
| **PHP** | 7.4 |
| **Shop** | https://localhost:8202/ |
| **Back office** | https://localhost:8202/admin-dev/ |
| **Database port** | `3326` |

The local shop covers only one row of the matrix. Use separate containers or CI jobs for the other rows.

## What to check at each endpoint

| Area | Required checks |
| --- | --- |
| **Code** | Install locked dependencies, check PHP syntax, and run unit tests. |
| **PrestaShop** | Install the module, compile both service containers, and list routes and commands. |
| **Employee flow** | Enroll, sign in with a code, and use one recovery code. |
| **Protected actions** | Test one module action, one theme action, and one employee reset. |
| **Cleanup** | Uninstall and confirm that all six module tables are gone. |

## Package check

Build the scoped package once with 8.1 through 8.4. Confirm that it contains `vendor-scoped/autoload.php`.

Confirm that it does **not** contain:

- `documentation/` or `docs/`;
- `tests/` or `tools/`;
- an unscoped `vendor/` directory.

## When to run the full matrix

Run every endpoint after a **dependency change**, **framework adapter change**, or **supported-version change**.

Do not raise the minimum PHP version only because a development computer uses a newer version. Create another compatibility branch when one safe code line cannot support both ends of the range.
