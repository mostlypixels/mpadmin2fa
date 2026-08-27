# Admin 2FA documentation for PrestaShop 9

This documentation applies to the `main` branch.

| Item | Supported value |
| --- | --- |
| Module release line | 3.x |
| PrestaShop | 9.0 through 9.2 |
| PHP runtime | 8.1 through 8.5 |
| Release-build PHP | 8.1 through 8.4 |

PrestaShop 9.0 supports PHP 8.1 through PHP 8.4.
PrestaShop 9.1 also supports PHP 8.5.
The release-build tool does not support PHP 8.5.
Use PHP 8.1 through PHP 8.4 to make the package.
Use PHP 8.5 only for module runtime tests.

The module declares support through PrestaShop 9.2.
Do not publish a 9.2 compatibility claim until its endpoint tests pass.

## Select a document

- [Use Docker](docker.md) to start the PrestaShop 9 test shop.
- [Understand the module](how-it-works.md) to learn the employee and administrator tasks.
- [Understand the architecture](architecture.md) to learn the software parts and data flows.
- [Use the compatibility matrix](development-matrix.md) to test the supported PHP range.
- [Prepare a release](release-strategy.md) to make a 3.x package.

## Documentation rules

These files use ASD-STE100 Simplified Technical English.
Use the same technical terms in all files.
Use short sentences.
Use one instruction in each numbered step.

Keep this directory in Git.
Do not put this directory in a module release.
The release tools exclude `documentation/` and reject an archive that contains it.
