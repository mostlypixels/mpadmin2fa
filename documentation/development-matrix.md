# Compatibility matrix for PrestaShop 1.7.8

This matrix applies only to the `1.x-ps17` branch.
The branch supports 1.7.8 only and PHP 7.1 through 7.4.

## Required endpoints

| Test boundary | PrestaShop | PHP | Purpose |
| --- | --- | --- | --- |
| Minimum boundary | PrestaShop 1.7.8 | PHP 7.1 | Prove the minimum supported runtime. |
| Maximum boundary | PrestaShop 1.7.8 | PHP 7.4 | Prove the maximum supported runtime. |

This branch does not support PrestaShop 1.7.0 through 1.7.7.
Those releases have different PHP ranges and different core behavior.
Use a new compatibility branch if the project adds those releases.

The Composer platform is PHP 7.1.
This setting prevents a dependency update from silently raising the minimum PHP version.

## Local Docker baseline

| Item | Value |
| --- | --- |
| Compose file | `docker-compose.mpadmin2fa.yml` |
| PrestaShop | PrestaShop 1.7.8.11 |
| PHP | PHP 7.4 |
| HTTPS store | https://localhost:8202/ |
| Back office | https://localhost:8202/admin-dev/ |
| Database | localhost:3326 |

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
