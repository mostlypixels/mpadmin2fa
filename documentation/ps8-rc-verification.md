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

The prior security/browser baseline is [run 33981604269](https://github.com/mostlypixels/mpadmin2fa/actions/runs/33981604269). Local validation of the release ZIP on PrestaShop 8.2.8/PHP 8.1 passed: 133 tests/570 assertions (two database-only skips), 64 HTTPS checks, 21 browser checks, 2 database tests/30 assertions, both historical upgrade paths, and all five lifecycle failure stages. Remote run 34049858590 passed all six jobs on code commit f4268d801818b97c037d8f95db8ae75148ab24cb. Unit coverage passed on PS8.0.5/PHP7.2, PS8.2.8/PHP7.2, and PS8.2.8/PHP8.1. Both installed-shop endpoints (PS8.0.0/PHP7.2 and PS8.2.8/PHP8.1) consumed the same candidate ZIP and passed 64 HTTPS checks, 21 browser checks, 2 database tests/30 assertions, both historical upgrade paths, and all five rollback stages.

The genuine source-built upgrade paths are 0.2.7 -> RC and 0.2.7 -> 0.2.8 -> RC, with pinned original installers and native version transitions. The second path reproduces the actual old timestamp migration and verifies the new RC migration repairs it.

The surrounding PS8 checkout and the other module branches are outside this change.

## Candidate artifact

- Code commit: f4268d801818b97c037d8f95db8ae75148ab24cb.
- CI: [run 34049858590](https://github.com/mostlypixels/mpadmin2fa/actions/runs/34049858590).
- Artifact name: mpadmin2fa-ps8-release.
- Archive: mpadmin2fa-v2.0.0-rc.1.zip.
- SHA-256: 4210b3ceddef2e05c06c88e50a3bb830e2a6615550634d83863e95b809d23d4b.
- Saved locally under dist/ci-34049858590/ with its matching .zip.sha256 file.

The ZIP contains 290 entries, the RC migration, and only the expected bridge under vendor/. Generated config.xml is absent. Subsequent documentation-only commits do not change which code commit produced this archive.

The tag publisher has been reviewed but publication itself has not been executed. No tag or release was created to test it. The independent review across PS9, PS8, PS1.7, and auxiliary branches remains the next task; the copyable handoff was delivered in the originating chat.
