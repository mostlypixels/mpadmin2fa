# Release guide for PrestaShop 9

## Release identity

| Item | Required value |
| --- | --- |
| **Branch** | `main` |
| **Module line** | 3.x |
| **Tag prefix** | `v3.` |
| **First public version** | `3.0.0` |
| **Build PHP** | 8.1 through 8.4 |

The current `0.x` version is for development. Never reuse the same tag on another branch.

## Choose the version number

| Change | Version action |
| --- | --- |
| Compatible bug fix | Increase the **patch** number. |
| Compatible feature | Increase the **minor** number. |
| First public PrestaShop 9 release | Use **3.0.0**. |
| Test release | Add a suffix such as `-rc.1`. |

## Prepare the release

1. Update `main` with a fast-forward pull.
2. Set `$this->version` in `mpadmin2fa.php`.
3. Check the PrestaShop range in `$this->ps_versions_compliancy`.
4. Check the PHP range in `composer.json`.
5. Update these documents when compatibility changed.
6. Run the full [compatibility matrix](development-matrix.md).
7. Review and commit the release changes.

**Build ZIP files with PHP 8.1 through 8.4.** The build tool does not support PHP 8.5.

## Build the ZIP

The tag text must match the module version:

```bash
php tools/release.php v3.0.0
```

The command validates Composer data, runs tests, builds scoped dependencies, and writes two files to `dist/`:

| File | Purpose |
| --- | --- |
| `mpadmin2fa-v3.0.0.zip` | Installable module package. |
| `mpadmin2fa-v3.0.0.zip.sha256` | File-integrity checksum. |

The command **does not publish anything**.

## Check the ZIP

Before publication, confirm that:

- every path starts with `mpadmin2fa/`;
- `mpadmin2fa.php`, `vendor-scoped/autoload.php`, `SBOM.json`, and `SHA256SUMS` exist;
- `documentation/`, `docs/`, `tests/`, `tools/`, and normal `vendor/` do not exist;
- this exact ZIP installs on a clean PrestaShop 9 shop.

## Publish safely

> [!WARNING]
> Pushing a `v*` tag starts the publication workflow.

1. Push `main` **without tags**.
2. Review the remote commit.
3. Create one annotated `v3.*` tag on that commit.
4. Inspect the tag and its changes.
5. Push **only that tag**.
6. Check the publication workflow.
7. Download the published ZIP and compare its SHA-256 value.

**Never use** `git push --tags` or `git push --follow-tags` for this release.

## Backport a fix

Move only the required fix commits to `main`. Adapt them for Symfony 6.4 and PHP 8.1 through 8.5, then run this branch's complete compatibility matrix.
