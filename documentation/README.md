# Admin 2FA documentation for PrestaShop 8

Use this folder to understand, test, and release the module.

## Supported versions

| Item | This branch supports |
| --- | --- |
| **Git branch** | `2.x-ps8` |
| **Module releases** | 2.x |
| **PrestaShop** | 8.0 through 8.2 |
| **PHP in the shop** | 7.2.5 through 8.1 |
| **PHP used to build a ZIP** | 8.1 through 8.4 |

The Composer platform is **PHP 7.2.5**. This stops dependency updates from silently raising the minimum PHP version.

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
