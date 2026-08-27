# Compatibility matrix for PrestaShop 9

This matrix applies only to the `main` branch.
The branch supports 9.0 through 9.2 and PHP 8.1 through 8.5.

## Required endpoints

| Test boundary | PrestaShop | PHP | Purpose |
| --- | --- | --- | --- |
| Minimum runtime | PrestaShop 9.0 | PHP 8.1 | Prove the minimum runtime. |
| Highest 9.0 runtime | PrestaShop 9.0 | PHP 8.4 | Prove the highest PHP version for PrestaShop 9.0. |
| Current upper runtime | PrestaShop 9.1 | PHP 8.5 | Prove the current highest PrestaShop 9 runtime. |
| Declared future line | PrestaShop 9.2 | Use the official range | Test this line before its first module release. |

PrestaShop 9.0 supports PHP 8.1 through PHP 8.4.
PrestaShop 9.1 also supports PHP 8.5.
The release-build tool does not support PHP 8.5.
Use PHP 8.1 through PHP 8.4 to make the package.
Use PHP 8.5 only for module runtime tests.

The module declares support through PrestaShop 9.2.
Do not publish a 9.2 compatibility claim until its endpoint tests pass.

## Local Docker baseline

| Item | Value |
| --- | --- |
| Compose file | `docker-compose.yml` |
| PrestaShop | the PrestaShop 9 development tree |
| PHP | PHP 8.1 |
| HTTPS store | https://localhost:9002/ |
| Back office | https://localhost:9002/admin-dev/ |
| Database | localhost:3396 |

The local stack is one endpoint of the compatibility matrix.
Use a separate container or CI job for each other endpoint.
Do not change the Composer platform to match only the local container.

## Release gate

Run this gate at each required endpoint:

1. Install production dependencies from the lock file.
2. Check all PHP files for syntax errors.
3. Run the unit tests.
4. Install the module on a clean shop.
5. Compile the development and production service containers.
6. List the module routes.
7. List the maintenance commands.
8. Enroll an employee with a TOTP factor.
9. Test one normal login challenge.
10. Test one recovery code.
11. Test one protected module action.
12. Test one protected theme action.
13. Reset an employee factor.
14. Uninstall the module.
15. Confirm that the six module tables no longer exist.

Run the scoped build one time with PHP 8.1 through 8.4.
Then inspect the package.
Confirm that it does not contain `documentation/`, `docs/`, tests, tools, or an unscoped vendor directory.

## Change control

Run the complete matrix after a dependency update.
Run the complete matrix after a framework adapter change.
Run the complete matrix after a supported-version change.

Do not raise the minimum PHP version because the development computer has a newer PHP version.
Create a new compatibility branch when one code line cannot support both endpoints safely.
