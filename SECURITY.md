# Security policy

## Status

This module is a public-beta candidate, not a stable security release. Do not deploy this work-in-progress branch to a production shop without independent review and environment-specific testing.

## Reporting

Use GitHub's [private vulnerability reporting form](https://github.com/mostlypixels/mpadmin2fa/security/advisories/new) rather than opening a public issue. Include the affected version, PrestaShop/PHP versions, reproduction steps and impact. Never include live credentials, TOTP secrets, recovery codes, cookie keys or customer data.

## Threat model

A database dump alone must not reveal TOTP shared secrets: ciphertexts and the wrapped module DEK are in the database, while the wrapping secret is PrestaShop's filesystem-held `_NEW_COOKIE_KEY_`.

TOTP mitigates compromised employee passwords and adds fresh verification around supported executable-code deployment operations. It cannot protect a server where an attacker can execute arbitrary PHP or read both application configuration and database. Third-party or future mutation endpoints require adapter review and compatibility tests.

Key loss or an unprepared `_NEW_COOKIE_KEY_` change fails closed. Test the two-phase rewrap procedure and CLI break-glass access before production use.
