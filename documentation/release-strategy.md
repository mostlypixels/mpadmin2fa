# Release guide for PrestaShop 9

This guide applies only to the `main` branch.
This branch owns module line 3.x.
Use tags that start with `v3.`.

## Version rules

- Increase the patch number for a compatible fix.
- Increase the minor number for a compatible feature.
- Keep major version 3 for PrestaShop 9.
- Use a suffix only for a real prerelease.

The current `0.x` module version is a development version.
Set the first public version on this branch to `3.0.0`.
Do not reuse the same Git tag on another branch.

## Prepare the branch

1. Select the `main` branch.
2. Get the current remote changes with a fast-forward update.
3. Add all required fixes for PrestaShop 9.
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

Run the release build with PHP 8.1 through PHP 8.4.

## Make the local package

Use a tag value that is equal to the module version:

```bash
php tools/release.php v3.0.0
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
10. Install this exact ZIP file on a clean PrestaShop 9 shop.

## Publish the release

> [!WARNING]
> A pushed `v*` tag starts the publication workflow.

1. Push the `main` branch without tags.
2. Review the exact remote commit.
3. Create one annotated `v3.*` tag on that commit.
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
Apply only the required commits to `main`.
Adapt the fix to Symfony 6.4 and PHP 8.1 through 8.5.
Run this branch's complete compatibility matrix.
Make a separate version, tag, and package for this branch.
