> [!CAUTION]
> **YO. This is entirely vibe-coded and not production ready. TEST IT. READ THE CODE. If you are not a developer: DO NOT INSTALL THIS.**

# Admin 2FA

Security-focused TOTP authentication for PrestaShop back-office employees. The module protects login and requires fresh MFA before module or theme operations that can deploy executable code.

This work-in-progress branch targets PrestaShop 9.0 through 9.2 and PHP 8.1+. It is not ready for production.

Start with the [documentation index](documentation/README.md). See
[IMPLEMENTATION.md](IMPLEMENTATION.md) for the implemented architecture and
[SECURITY.md](SECURITY.md) for reporting and threat-model details. The older
`spec.md` and `HANDOFF.md` files are historical design records.

## Install

```bash
ln -s "$(pwd)" /path/to/prestashop/modules/mpadmin2fa
php bin/console prestashop:module install mpadmin2fa
```

## Requirements

- PrestaShop >= 9.0.0 and <= 9.2.x
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

## Release

Each PrestaShop compatibility branch has its own module major version. Read the
[release strategy](documentation/release-strategy.md) before changing versions
or pushing tags. Pushing a valid `v*` tag is the publication trigger; routine
branch pushes do not create releases.

To produce and verify release artifacts locally without publishing them:

```bash
php tools/release.php <matching-tag>
```

License: MIT.
