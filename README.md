# Admin 2FA

Security-focused TOTP authentication for PrestaShop back-office employees. The module protects login and requires fresh MFA before module or theme operations that can deploy executable code.

This work-in-progress branch targets PrestaShop 9 and PHP 8.1+. It is not ready for production.

See [IMPLEMENTATION.md](IMPLEMENTATION.md) for the implemented architecture and [SECURITY.md](SECURITY.md) for reporting and threat-model details. The older `spec.md` and `HANDOFF.md` files are historical design records.

## Install

```bash
ln -s "$(pwd)" /path/to/prestashop/modules/mpadmin2fa
php bin/console prestashop:module install mpadmin2fa
```

## Requirements

- PrestaShop >= 9.0.0
- PHP >= 8.1

## Development

```bash
composer install
composer test
composer build:tools
composer build:scoped
```

Production dependencies and tests remain compatible with PHP 7.2.5. Build tooling is installed separately and requires PHP 8.1-8.4.

The scoped release is written to `build/mpadmin2fa` with an SBOM and SHA-256 checksums. Build with PHP 8.1–8.4.

License: MIT.
