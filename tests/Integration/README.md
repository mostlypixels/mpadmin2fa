# PS 1.7 beta-RC package verification

These scripts run against a disposable installed PrestaShop shop. Its
modules/mpadmin2fa directory must contain the artifact from
tools/build-scoped.php, including SHA256SUMS. Keep this harness in a separate
directory. It requires Bash, PHP with cURL/DOM/PDO MySQL, OpenSSL, Apache with
the matching PHP module, and the module's development Composer dependencies.

The workflow tests PrestaShop 1.7.8.0 and 1.7.8.11 on PHP 7.1 and 7.4. It downloads
the artifact produced by the package job. PHPUnit's integration bootstrap
disables the harness's module autoload mapping and verifies that the repository
class comes from the package.

Set these variables before running bash tests/Integration/run_lifecycle.sh:

- MP2FA_INTEGRATION=1 explicitly enables destructive tests.
- MP2FA_PS_ROOT is the absolute path of the disposable installed shop.
- MP2FA_TEST_EMAIL and MP2FA_TEST_PASSWORD identify the disposable administrator.
  CI generates and masks a fresh random password on every run.
- MP2FA_HISTORICAL_OUTPUT is the output directory of
  bash tests/Integration/build_historical_package.sh.
- MP2FA_APACHE_PHP_MODULE optionally selects the matching Apache PHP module.

The HTTPS harness starts its own Apache instance on loopback ports 8443 and 8080.
It trusts only its temporary certificate and uses cURL without JavaScript.
It verifies real password login, MFA enrollment and challenge forms, CSRF,
invalid and valid TOTP, replay rejection, fresh-login invalidation, logout,
SuperAdmin approvals, and recovery/replacement restrictions. An expired step-up
grant must reject every tested modern and legacy module action. Module database
records must remain unchanged after those rejected actions.

The database suite starts ten independent PHP workers, waits for every worker's
database connection to be ready, and then releases their shared start barrier.
It checks missing and existing counter rows, every returned count, the final
stored count, exact UTC blocking delays, the delay cap, reset ordering, and
subject isolation. CI repeats the suite ten times. Separate controller tests
assert the approval target state and exact approval/denial audit metadata.
The alert harness captures the mail transport in its CLI process; no test alert
is sent externally. It checks threshold alerts, rejection while blocked, reset
behavior, and resilience to a failed mail transport.

Lifecycle verification covers clean installation, duplicate installation,
reconciliation, partial-state repair, and uninstall with missing tables, tabs,
hooks, and configuration. Failure injection modifies
only a separate installed package copy, at five explicit boundaries: before the
parent installer and after schema, configuration, hooks, and tabs. It compares
hashes of the pre-install rows, including employee/access, module, hook, tab, and
authorization tables, then restores the original package file. Every injected
failure is followed by an immediate successful reinstall and uninstall.
Private runtime directories and a shutdown trap keep fixture state out of the
source tree; only sanitized test output is retained by CI.

For this PS 1.7 beta-RC, the historical upgrade fixture is version 0.2.7 at
96a0a0d15a1247d90825b800b9d8a41936d21383. There were no published release assets
to download. The builder archives that exact historical tree and installs its
own production lockfile. It does not relabel the current package or merely
change the installed database version. Upgrade checks preserve enrolled secrets,
recovery codes, keys, approval/audit history, rate-limit history, and policy
values. An injected failure after schema migration must leave version 0.2.7
recorded and preserve the historical data; restoring the package and retrying
must succeed. The checks repeat the real upgrade entry point, compare schema,
indexes, tabs, hooks, configuration keys, roles, and access against a clean
installation, and verify cleanup.

Run PHP/application commands as the application user when using Docker.
Logs may point to private temporary response files for local diagnosis; do not
publish cookies, session files, CSRF values, enrollment secrets, or recovery codes.
