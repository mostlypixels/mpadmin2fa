# Admin 2FA documentation for PrestaShop 9

Use this folder to understand, test, and release the module.

## Supported versions

| Item | This branch supports |
| --- | --- |
| **Git branch** | `main` |
| **Module releases** | 3.x |
| **PrestaShop** | 9.0 through 9.1 |
| **PHP in the shop** | 8.1 through 8.5 |
| **PHP used to build a ZIP** | 8.1 through 8.4 |

**Important:** PrestaShop 9.2 is a provisional target, not a supported release. Add it only after a final 9.2 tag passes the complete installed-package matrix.

## Find the right page

| I want to... | Read... |
| --- | --- |
| Start the test shop | [Docker](docker.md) |
| Understand the employee experience | [How it works](how-it-works.md) |
| Find the important code | [Architecture](architecture.md) |
| Check supported versions | [Compatibility matrix](development-matrix.md) |
| Build and publish a ZIP | [Release guide](release-strategy.md) |

## About this folder

The **documentation stays in Git** so maintainers can read it.

The **documentation is not included in release ZIP files**. The release tool rejects a ZIP that contains `documentation/` or the old `docs/` directory.
