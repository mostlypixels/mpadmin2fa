# Admin 2FA documentation for PrestaShop 8

This documentation applies to the `2.x-ps8` branch.

| Item | Supported value |
| --- | --- |
| Module release line | 2.x |
| PrestaShop | 8.0 through 8.2 |
| PHP runtime | 7.2.5 through 8.1 |
| Release-build PHP | 8.1 through 8.4 |

PrestaShop 8 requires PHP 7.2.5 or a later supported version.
PrestaShop 8 supports PHP through PHP 8.1.
The Composer platform is PHP 7.2.5.
This setting prevents a dependency update from silently raising the minimum PHP version.

## Select a document

- [Use Docker](docker.md) to start the PrestaShop 8 test shop.
- [Understand the module](how-it-works.md) to learn the employee and administrator tasks.
- [Understand the architecture](architecture.md) to learn the software parts and data flows.
- [Use the compatibility matrix](development-matrix.md) to test the supported PHP range.
- [Prepare a release](release-strategy.md) to make a 2.x package.

## Documentation rules

These files use ASD-STE100 Simplified Technical English.
Use the same technical terms in all files.
Use short sentences.
Use one instruction in each numbered step.

Keep this directory in Git.
Do not put this directory in a module release.
The release tools exclude `documentation/` and reject an archive that contains it.
