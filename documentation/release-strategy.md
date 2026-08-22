# Release strategy

mpadmin2fa maintains separate code lines for incompatible PrestaShop
generations. Git tags are repository-wide, so every release across every branch
must have a unique version.

## Compatibility lines

The module major version identifies the supported PrestaShop generation.

| Module line | PrestaShop compatibility | Maintenance branch | Example tag |
| --- | --- | --- | --- |
| 1.x | PrestaShop 1.7.8 | `1.x-ps17` | `v1.0.0` |
| 2.x | PrestaShop 8 | `2.x-ps8` | `v2.0.0` |
| 3.x | PrestaShop 9 | `main` | `v3.0.0` |

`main` is the current development line for PrestaShop 9. It may eventually be
renamed to `3.x-ps9`, but the version mapping does not depend on that rename.

Compatibility labels must not be appended to otherwise identical versions. For
example, do not use `v0.2.3-ps8`: the current workflow treats every hyphenated
version as a prerelease.

## Version meanings

Within a compatibility line:

- Increment the patch number for compatible bug and security fixes.
- Increment the minor number for backward-compatible features.
- The major number remains tied to the PrestaShop compatibility generation.
- Use a SemVer suffix only for an actual prerelease, such as
  `v3.1.0-rc.1`.

The branches have independent release histories. Their minor and patch numbers
do not need to remain synchronized.

Examples:

- A PS9-only feature can release as `v3.1.0` without changing the legacy
  branches.
- A security fix may release as `v1.0.4`, `v2.2.2`, and `v3.1.1` after
  being backported to each maintained line.

## Initial version normalization

Before the first public release from each line, set the version declared in
`mpadmin2fa.php` to:

- `1.0.0` on `1.x-ps17`;
- `2.0.0` on `2.x-ps8`;
- `3.0.0` on `main`.

The repository currently has no published Git tags, so this normalization can
be performed without invalidating an existing release history. Historical
`0.x` versions are development versions and must not be reused across
compatibility branches.

## Preparing a release

Perform release preparation on the branch that owns the compatibility line:

1. Confirm that the branch contains the intended changes and all required
   backports.
2. Set `$this->version` in `mpadmin2fa.php`.
3. Confirm that `$this->ps_versions_compliancy` still describes only that
   branch's supported PrestaShop generation.
4. Update user-facing release notes or documentation.
5. Run the branch's complete test and package verification process.
6. Commit the version and release preparation.
7. Push the branch without pushing tags.
8. Review the exact commit that will be tagged.
9. Create one annotated tag on that commit.
10. Push that exact tag only after review.

Example for a PS9 release:

```bash
git switch main
git pull --ff-only
composer test
php tools/release.php v3.0.0
git tag -a v3.0.0 -m "mpadmin2fa 3.0.0 for PrestaShop 9"
git show --stat v3.0.0
git push origin main
git push origin v3.0.0
```

The local release command creates and verifies `dist/` artifacts. It does not
contact GitHub or publish a release.

## Backporting fixes

Develop and review a fix on the appropriate primary branch, then cherry-pick it
onto every other maintained compatibility branch where it applies. Adapt the
implementation to the APIs supported by that branch instead of merging entire
compatibility branches together.

Each branch receives its own version bump, tests, commit, tag, archive, and
GitHub Release. A failure or delay on one compatibility line must not block
releasing a necessary fix on another line.

## Publication trigger

The tagged-release workflow is triggered only by pushing a tag whose name
starts with `v`. A normal branch push does not create a release.

When triggered, the workflow:

1. checks out the tagged commit;
2. configures PHP and Composer;
3. validates the tag format;
4. checks that the tag version exactly matches the module version;
5. validates Composer metadata, installs dependencies, and runs tests;
6. builds and verifies the scoped module archive;
7. uploads the build output as a workflow artifact;
8. publishes the ZIP and SHA-256 checksum as a non-draft GitHub Release.

A tag containing a SemVer suffix is published as a prerelease.

The workflow file and release tools are read from the tagged commit. Therefore,
the release automation must be committed or backported to every compatibility
branch before that branch can publish releases.

## Safety rules

Treat pushing an exact `v*` tag as pressing the release button.

Safe operations:

```bash
git push origin main
php tools/release.php v3.0.0
```

Operations that may publish releases and must not be used casually:

```bash
git push origin v3.0.0
git push --tags
git push --follow-tags
gh release create ...
```

Do not enable an IDE's “push tags” option during routine branch pushes. A local
tag is not published by itself, but a later bulk tag push can publish it.

Before the workflow is enabled for public releases, add an explicit publication
guard such as a protected GitHub Environment with required approval or a
separate manually dispatched publish job. Tag validation prevents malformed or
mismatched packages; it does not protect against accidentally pushing a valid
release tag.

## Automation invariants

The release automation should be extended to reject:

- a `v1.*` tag unless the module targets PrestaShop 1.7;
- a `v2.*` tag unless the module targets PrestaShop 8;
- a `v3.*` tag unless the module targets PrestaShop 9;
- a tag whose version differs from `$this->version`;
- an archive containing development files or files outside `mpadmin2fa/`.

Until the compatibility-line checks are implemented in code, reviewers must
verify the major-version and PrestaShop mapping manually before pushing a tag.
