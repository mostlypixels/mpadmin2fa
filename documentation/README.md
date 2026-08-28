# Admin 2FA documentation for PrestaShop 1.7.8

Use this folder to understand, test, and release the module.

## Supported versions

| Item | This branch supports |
| --- | --- |
| **Git branch** | `1.x-ps17` |
| **Module releases** | 1.x |
| **PrestaShop** | 1.7.8 only |
| **PHP in the shop** | 7.1 through 7.4 |
| **PHP used to build a ZIP** | 8.1 through 8.4 |

This branch does **not** support PrestaShop 1.7.0 through 1.7.7. The Composer platform stays at **PHP 7.1**.

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
