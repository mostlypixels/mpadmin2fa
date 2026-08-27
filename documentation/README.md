# Admin 2FA documentation for PrestaShop 1.7.8

This documentation applies to the `1.x-ps17` branch.

| Item | Supported value |
| --- | --- |
| Module release line | 1.x |
| PrestaShop | 1.7.8 only |
| PHP runtime | 7.1 through 7.4 |
| Release-build PHP | 8.1 through 8.4 |

This branch does not support PrestaShop 1.7.0 through 1.7.7.
Those releases have different PHP ranges and different core behavior.
Use a new compatibility branch if the project adds those releases.

The Composer platform is PHP 7.1.
This setting prevents a dependency update from silently raising the minimum PHP version.

## Select a document

- [Use Docker](docker.md) to start the PrestaShop 1.7.8 test shop.
- [Understand the module](how-it-works.md) to learn the employee and administrator tasks.
- [Understand the architecture](architecture.md) to learn the software parts and data flows.
- [Use the compatibility matrix](development-matrix.md) to test the supported PHP range.
- [Prepare a release](release-strategy.md) to make a 1.x package.

## Documentation rules

These files use ASD-STE100 Simplified Technical English.
Use the same technical terms in all files.
Use short sentences.
Use one instruction in each numbered step.

Keep this directory in Git.
Do not put this directory in a module release.
The release tools exclude `documentation/` and reject an archive that contains it.
