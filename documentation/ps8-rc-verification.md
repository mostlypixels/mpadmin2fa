# PS8 RC release-path verification

Candidate: public tag v2.0.0-rc.1; internal module version 2.0.0rc1. No tag or GitHub Release has been created.

## Corrected release blockers

- The release build now runs within an isolated PrestaShop checkout with platform dependencies present, and installs the separate build tools itself.
- Archive validation permits only the exact PrestaShop vendor/autoload.php bridge. Unscoped vendor dependencies and development paths remain rejected.
- The release build excludes generated config.xml so local runtime metadata cannot leak a stale version into the ZIP.
- PS8's eight-character module-version database field cannot store 2.0.0-rc.1. The explicit tag/runtime mapping preserves the candidate number and is covered by unit tests.
- The RC migration uses the native-compatible filename upgrade-2.0.0rc1.php and repairs installations already on development 0.2.8.
- CI builds one release ZIP and both installed-shop lifecycle jobs consume that artifact. The tag publisher calls the same validation workflow and downloads its validated artifact without rebuilding it.

## Verification

The prior security/browser baseline is [run 33981604269](https://github.com/mostlypixels/mpadmin2fa/actions/runs/33981604269). Local validation of the release ZIP on PrestaShop 8.2.8/PHP 8.1 passed: 133 tests/570 assertions (two database-only skips), 64 HTTPS checks, 21 browser checks, 2 database tests/30 assertions, both historical upgrade paths, and all five lifecycle failure stages. The combined remote run will additionally validate the same ZIP on PrestaShop 8.0.0/PHP 7.2; results will be recorded when it finishes.

The genuine source-built upgrade paths are 0.2.7 -> RC and 0.2.7 -> 0.2.8 -> RC, with pinned original installers and native version transitions. The second path reproduces the actual old timestamp migration and verifies the new RC migration repairs it.

The surrounding PS8 checkout and the other module branches are outside this change.
