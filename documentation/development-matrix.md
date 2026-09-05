# Compatibility matrix for PrestaShop 9

## Versions that must work

| Test | PrestaShop | PHP | Why it matters |
| --- | --- | --- | --- |
| **Lowest runtime** | 9.0.0 | 8.1 | Protects shops on the oldest supported PHP. |
| **Highest 9.0 runtime** | 9.0.3 | 8.4 | Checks the top of the 9.0 range. |
| **Highest stable runtime** | 9.1.5 | 8.5 | Checks the newest supported PHP. |
| **Future target** | Final 9.2 tag | Official range | Must pass before publishing a 9.2 claim. |

**Important:** As of 2026-09-05, upstream has published only the [9.2.0-beta.1 prerelease](https://github.com/PrestaShop/PrestaShop/releases/tag/9.2.0-beta.1). The module's supported maximum remains 9.1.99 until a final 9.2 tag passes this matrix.

**Build ZIP files with PHP 8.1 through 8.4.** The build tool does not support PHP 8.5.

## Automated installed-package rows

| PrestaShop | PHP | Coverage |
| --- | --- | --- |
| 9.0.0 | 8.1 | Built package install, upgrade, atomic database checks, 64 HTTPS requests, injected rollback, and uninstall. |
| 9.1.5 | 8.4 | Built package install, upgrade, atomic database checks, 64 HTTPS requests, injected rollback, and uninstall. |

The ordinary compatibility jobs also cover 9.0.3/PHP 8.4 and 9.1.5/PHP 8.5. A real headless Chromium job verifies the AJAX step-up redirect listener independently.

The final 9.2 row is intentionally absent. Do not substitute a beta or development branch for final-release evidence.

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
