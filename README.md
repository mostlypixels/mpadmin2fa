> [!CAUTION]
> This module is not ready for production. Test it and review the code before you install it.

# Admin 2FA

Admin 2FA gives time-based one-time password protection to PrestaShop back-office employees.
It also requires a recent code before an employee changes modules, themes, or module security settings.

This branch supports 8.0 through 8.2 and PHP 7.2.5 through 8.1.

Start with the [documentation index](documentation/README.md).
Read [SECURITY.md](SECURITY.md) to report a security problem.

## Install the module

1. Put this repository in the PrestaShop `modules/mpadmin2fa` directory.
2. Install the production dependencies.
3. Run this command from the PrestaShop root:

```bash
php bin/console prestashop:module install mpadmin2fa
```

## Requirements

- PrestaShop 8.0 through 8.2
- PHP 7.2.5 through 8.1
- HTTPS for authenticator enrollment

## Run the development checks

Run these commands from the module directory:

```bash
composer install
composer test
composer build:tools
composer build:scoped
```

The module runtime supports PHP 7.2.5. The separate release-build tools require PHP 8.1 through PHP 8.4.
The scoped module is in `build/mpadmin2fa`.
The build contains an SBOM and SHA-256 checksums.

## Prepare a release

Read the [release guide](documentation/release-strategy.md) before you change a version or push a tag.
A branch push does not create a release.
A valid `v*` tag starts the publication workflow.

Make and verify a package without publication:

```bash
php tools/release.php <matching-tag>
```

License: MIT.
