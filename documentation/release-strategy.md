# Release guide for PrestaShop 8

## Release identity

| Item | Required value |
| --- | --- |
| **Branch** | `2.x-ps8` |
| **Module line** | 2.x |
| **Tag prefix** | `v2.` |
| **First public version** | `2.0.0` |
| **Build PHP** | 8.1 through 8.4 |

The current `0.x` version is for development. Never reuse the same tag on another branch.

## Choose the version number

| Change | Version action |
| --- | --- |
| Compatible bug fix | Increase the **patch** number. |
| Compatible feature | Increase the **minor** number. |
| First public PrestaShop 8 release | Use **2.0.0**. |
| Test release | Add a suffix such as `-rc.1`. |

## Prepare the release

1. Update `2.x-ps8` with a fast-forward pull.
2. Set `$this->version` in `mpadmin2fa.php`.
3. Check the PrestaShop range in `$this->ps_versions_compliancy`.
4. Check the PHP range in `composer.json`.
5. Update these documents when compatibility changed.
6. Run the full [compatibility matrix](development-matrix.md).
7. Review and commit the release changes.

**Runtime and build PHP are different.** The shop can use PHP 7.2.5, but the ZIP builder needs PHP 8.1 through 8.4.

## Build the ZIP

The tag text must match the module version:

```bash
php tools/release.php v2.0.0
```

The command validates Composer data, runs tests, builds scoped dependencies, and writes two files to `dist/`:

| File | Purpose |
| --- | --- |
| `mpadmin2fa-v2.0.0.zip` | Installable module package. |
| `mpadmin2fa-v2.0.0.zip.sha256` | File-integrity checksum. |

The command **does not publish anything**.

## Check the ZIP

Before publication, confirm that:

- every path starts with `mpadmin2fa/`;
- `mpadmin2fa.php`, `vendor-scoped/autoload.php`, `SBOM.json`, and `SHA256SUMS` exist;
- `documentation/`, `docs/`, `tests/`, `tools/`, and normal `vendor/` do not exist;
- this exact ZIP installs on a clean PrestaShop 8 shop.

## Publish safely

> [!WARNING]
> Pushing a `v*` tag starts the publication workflow.

1. Push `2.x-ps8` **without tags**.
2. Review the remote commit.
3. Create one annotated `v2.*` tag on that commit.
4. Inspect the tag and its changes.
5. Push **only that tag**.
6. Check the publication workflow.
7. Download the published ZIP and compare its SHA-256 value.

**Never use** `git push --tags` or `git push --follow-tags` for this release.

## Backport a fix

Move only the required fix commits to `2.x-ps8`. Adapt them for Symfony 4.4 and PHP 7.2.5 through 8.1, then run this branch's complete compatibility matrix.
