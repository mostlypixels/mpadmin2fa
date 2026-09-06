# Release guide for PrestaShop 8

## Release identity

| Item | Required value |
| --- | --- |
| **Branch** | `2.x-ps8` |
| **Module line** | 2.x |
| **Tag prefix** | `v2.` |
| **First public version** | `2.0.0` |
| **Build PHP** | 8.1 through 8.4 |

The PS8 candidate is public tag v2.0.0-rc.1 with internal module version 2.0.0rc1. PrestaShop 8 stores module versions in a VARCHAR(8) field; the release builder validates this explicit RC mapping. Stable versions have no suffix. Tags remain unique to their release line.

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

The release builder validates the tag against the internal module version. For the current RC:

```bash
php tools/release.php v2.0.0-rc.1
```

Run the command from a clean module checkout placed at modules/mpadmin2fa inside an isolated PrestaShop checkout with its Composer dependencies installed. The command installs module and separate build-tool dependencies, validates Composer data, runs tests, builds scoped dependencies, and writes two files to `dist/`:

| File | Purpose |
| --- | --- |
| `mpadmin2fa-v2.0.0-rc.1.zip` | Installable module package. |
| `mpadmin2fa-v2.0.0-rc.1.zip.sha256` | File-integrity checksum. |

The command **does not publish anything**.

## Check the ZIP

Before publication, confirm that:

- every path starts with `mpadmin2fa/`;
- `mpadmin2fa.php`, `vendor-scoped/autoload.php`, `SBOM.json`, and `SHA256SUMS` exist;
- documentation/, docs/, tests/, tools/, node_modules/, and the PrestaShop source checkout are absent;
- vendor/ contains only the exact vendor/autoload.php bridge to vendor-scoped/autoload.php; all real dependencies are scoped;
- this exact ZIP installs on a clean PrestaShop 8 shop.

## Publish safely

> [!WARNING]
> Pushing a v2.* tag starts validation and then publication.

1. Push `2.x-ps8` **without tags**.
2. Review the remote commit.
3. Create one annotated `v2.*` tag on that commit.
4. Inspect the tag and its changes.
5. Push **only that tag**.
6. Check the publication workflow. It calls the same six-job CI workflow, including both lifecycle targets installing the exact uploaded ZIP. Publication waits for every job to pass and downloads that validated artifact without rebuilding it.
7. Download the published ZIP and compare its SHA-256 value.

**Never use** `git push --tags` or `git push --follow-tags` for this release.

## Backport a fix

Move only the required fix commits to `2.x-ps8`. Adapt them for Symfony 4.4 and PHP 7.2.5 through 8.1, then run this branch's complete compatibility matrix.

## Development upgrades and RC preparation

The RC includes upgrade-2.0.0rc1.php. PS8 splits migration filenames on a hyphen, so a filename containing the public tag suffix would be ignored. This migration reuses the idempotent 0.2.8 repair and therefore covers development installations already reporting 0.2.8.

CI builds both historical source packages from pinned commits and tests 0.2.7 -> RC and 0.2.7 -> 0.2.8 -> RC. Native installation and upgrade must retain the complete internal RC version in the database. Neither historical path simulates installed-version metadata.

Branch CI produces the same candidate ZIP artifact as tag validation. An RC ZIP may be downloaded for review without creating a tag or publishing a release. A fresh review across the concurrently developed module branches is recorded separately in the handoff.
