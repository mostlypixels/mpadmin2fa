# Release guide for PrestaShop 1.7.8

This guide applies only to the `1.x-ps17` branch.
This branch owns module line 1.x.
Use tags that start with `v1.`.

## Version rules

- Increase the patch number for a compatible fix.
- Increase the minor number for a compatible feature.
- Keep major version 1 for PrestaShop 1.7.8.
- Use a suffix only for a real prerelease.

The current `0.x` module version is a development version.
Set the first public version on this branch to `1.0.0`.
Do not reuse the same Git tag on another branch.

## Prepare the branch

1. Select the `1.x-ps17` branch.
2. Get the current remote changes with a fast-forward update.
3. Add all required fixes for PrestaShop 1.7.8.
4. Set `$this->version` in `mpadmin2fa.php`.
5. Confirm the PrestaShop range in `$this->ps_versions_compliancy`.
6. Confirm the PHP range in `composer.json`.
7. Update these version-specific documents.
8. Run the full [compatibility matrix](development-matrix.md).
9. Run the unit tests.
10. Build the scoped module.
11. Confirm that the documentation is absent from the build.
12. Review all branch changes.
13. Commit the release preparation.

The module runtime supports PHP 7.1. The separate release-build tools require PHP 8.1 through PHP 8.4.

## Make the local package

Use a tag value that is equal to the module version:

```bash
php tools/release.php v1.0.0
```

The command validates Composer data.
It runs the tests and makes the scoped module.
It makes a ZIP file and a SHA-256 file in `dist/`.
It does not publish a release.

## Inspect the package

1. Open the ZIP file list.
2. Confirm that all entries start with `mpadmin2fa/`.
3. Confirm that `mpadmin2fa/mpadmin2fa.php` exists.
4. Confirm that `mpadmin2fa/vendor-scoped/autoload.php` exists.
5. Confirm that `mpadmin2fa/SBOM.json` exists.
6. Confirm that `mpadmin2fa/SHA256SUMS` exists.
7. Confirm that `documentation/` does not exist.
8. Confirm that `docs/` does not exist.
9. Confirm that `tests/` and `tools/` do not exist.
10. Install this exact ZIP file on a clean PrestaShop 1.7.8 shop.

## Publish the release

> [!WARNING]
> A pushed `v*` tag starts the publication workflow.

1. Push the `1.x-ps17` branch without tags.
2. Review the exact remote commit.
3. Create one annotated `v1.*` tag on that commit.
4. Show the tag and its file changes.
5. Push only that tag.
6. Check the workflow result.
7. Download the published ZIP file.
8. Compare its SHA-256 value with the published checksum.

Do not use `git push --tags`.
Do not use `git push --follow-tags`.
Do not enable automatic tag push in an editor.

## Backport a fix

Develop the fix on its primary branch.
Apply only the required commits to `1.x-ps17`.
Adapt the fix to Symfony 3.4 and PHP 7.1 through 7.4.
Run this branch's complete compatibility matrix.
Make a separate version, tag, and package for this branch.
