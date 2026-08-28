# Compatibility matrix for PrestaShop 9

## Versions that must work

| Test | PrestaShop | PHP | Why it matters |
| --- | --- | --- | --- |
| **Lowest runtime** | 9.0 | 8.1 | Protects shops on the oldest supported PHP. |
| **Highest 9.0 runtime** | 9.0 | 8.4 | Checks the top of the 9.0 range. |
| **Highest current runtime** | 9.1 | 8.5 | Checks the newest supported PHP. |
| **Future declared line** | 9.2 | Official range | Must pass before publishing a 9.2 claim. |

**Important:** PrestaShop 9.0 supports PHP through 8.4. PrestaShop 9.1 also supports PHP 8.5. Test the final 9.2 release before publishing that claim.

**Build ZIP files with PHP 8.1 through 8.4.** The build tool does not support PHP 8.5.

## Local Docker shop

| Item | Local value |
| --- | --- |
| **Compose file** | `docker-compose.yml` |
| **PrestaShop** | PrestaShop 9 development tree |
| **PHP** | 8.1 |
| **Shop** | https://localhost:9002/ |
| **Back office** | https://localhost:9002/admin-dev/ |
| **Database port** | `3396` |

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
