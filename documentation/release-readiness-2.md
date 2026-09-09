# Release readiness review — amended

Review date: 2026-09-08

This is the second amended review of the PS9, PS8, and PS1.7 release lines.
It incorporates the GitHub issues raised by the independent `main` branch audit,
the cross-branch comparison, the remediation commits, and the final
non-publishing release-workflow runs.

## Decision

All confirmed P0 security and data-integrity blockers are fixed and closed.
Each branch now produces one RC archive, installs that exact archive throughout
its compatibility matrix, and verifies the same artifact at the publication
handoff. No tag or release was created.

- **PS8 is ready to circulate as an RC.** It has the complete installed-browser,
  genuine historical-upgrade, database, rollback, packaging, and release-flow
  evidence requested for this review.
- **PS9 and PS1.7 remain conditional RCs.** Their security, HTTPS, database,
  rollback, packaging, and release-flow suites are green. Real Chrome verifies
  the installed JavaScript listener, but neither line yet drives the complete
  enrollment and challenge journey through the installed PrestaShop UI as PS8
  does. Issue #39 remains open for this gap.
- **No line is declared stable-release ready.** The unfinished shared timezone
  corpus and hardening gates (#28 and #29), plus the P2 issues, remain explicit
  follow-up work.

## Exact branch inventory

Remote references were refreshed before the final review. The tested code
commits were synchronized with `origin`, and all six worktrees were clean under
native Git after the documentation updates were committed. The commit containing
this report and the PS9 plan deletion, and the separate PS1.7 plan-deletion
commit, are documentation-only follow-ups. They do not replace the exact tested
code commits below and contain no product, test, package, or workflow changes.

| Release line | Branch | Exact tested code commit | Post-test branch change |
|---|---|---|---|
| PS9 / 3.x | `main` | `95466902598bce332e5449e6b7963046dfd739b8` | Report and obsolete-plan deletion only |
| PS8 / 2.x | `2.x-ps8` | `1d06806320fbc9b63d7bd23e777480e3a4c3a24a` | None; tested commit remains the branch tip |
| PS1.7 / 1.x | `1.x-ps17` | `322a4c20af243253f20b4472ea75241825b28763` | Obsolete-plan deletion only |

The only auxiliary branches are local. Each is clean, is already an ancestor of
its release branch, and has no unique work remaining.

| Auxiliary branch | Commit | Disposition |
|---|---|---|
| `codex/port-ps17` | `099fca1432a9f4e041ed31f3589197be28bff13e` | contained in `1.x-ps17` |
| `codex/remediate-ps17` | `d9cbbdca42826f94ee7c0d60ce17081760b7c2b1` | contained in `1.x-ps17` |
| `codex/port-ps8` | `972d11589e908cccec0883e9201ea64c3244af25` | contained in `2.x-ps8` |

No additional local or remote branch was found. The obsolete
`documentation/audit-fix-plan.md` copies were removed as previously approved.

## Validated RC artifacts

| Line | Candidate | SHA-256 | Release dry run |
|---|---|---|---|
| PS9 | `mpadmin2fa-v3.0.0-rc.1.zip` | `d58c248f462553c33232fe0fcafc57d5eedb779294a717dbf658ffd20bc7dac7` | [34251403367](https://github.com/mostlypixels/mpadmin2fa/actions/runs/34251403367) |
| PS8 | `mpadmin2fa-v2.0.0-rc.1.zip` | `778452ae7743675021f177de5d180de226493a8a0f2b721dec93d0aa6544327f` | [34251406805](https://github.com/mostlypixels/mpadmin2fa/actions/runs/34251406805), attempt 2 |
| PS1.7 | `mpadmin2fa-v1.0.0-rc.1.zip` | `ab4507243c86a1e32f94119ad6b3fee7446db636362bbbd6c5fe8c5a082de541` | [34251410312](https://github.com/mostlypixels/mpadmin2fa/actions/runs/34251410312), attempt 2 |

Downloaded copies are stored under each module worktree:

- PS9: `dist/release-dry-run-34251403367/`
- PS8: `dist/release-dry-run-34251406805/`
- PS1.7: `dist/release-dry-run-34251410312/`

The first PS8 and PS1.7 attempts encountered GitHub artifact-service HTTP 403
responses before installation. Re-running the affected jobs at the same commits
succeeded. The package digests remained identical, so these were transfer
failures rather than product or reproducibility failures.

## RC readiness matrix

| Area | PS9 / 3.x | PS8 / 2.x | PS1.7 / 1.x |
|---|---|---|---|
| Security gating | Confirmed | Confirmed | Confirmed |
| Native profile permissions and SuperAdmin policy | Confirmed | Confirmed | Confirmed |
| Generated test credentials and password masking | Confirmed | Confirmed | Confirmed |
| Enrollment/challenge HTTPS behavior | 70 live checks on both lifecycle endpoints | 70 live checks on both lifecycle endpoints | 114 live checks on all four lifecycle combinations |
| Background XHR/fetch and same-form behavior | Confirmed in real Chrome with installed listener | Confirmed in installed-browser suite | Confirmed in real Chrome with installed listener |
| Full installed PrestaShop browser journey | Partial; HTTP and browser evidence are split | Confirmed; 25 checks per endpoint | Partial; HTTP and browser evidence are split |
| Database behavior | 2 tests / 30 assertions on both lifecycle endpoints | 2 tests / 30 assertions on both lifecycle endpoints | 7 tests / 194 assertions, repeated 10 times on every lifecycle combination |
| Genuine historical upgrades | Not applicable; pinned packages are PS8-only | Direct 0.2.7 to RC and chained 0.2.7 to 0.2.8 to RC confirmed | Not applicable; pinned packages are PS8-only |
| Legacy `date_upd` and partial migration repair | Migration code and simulated repair fixture confirmed; no genuine PS9 predecessor exists | Confirmed through both genuine paths, strict SQL writes, failure/retry, and repeat execution | Migration code retained; no genuine PS1.7 predecessor exists |
| Duplicate installation | Confirmed non-destructive | Confirmed non-destructive | Confirmed non-destructive |
| Five rollback stages and immediate reinstall | Confirmed on both endpoints | Confirmed on both endpoints | Confirmed on all four combinations |
| Compatibility endpoints | PS 9.0.0/PHP 8.1, 9.0.3/PHP 8.4, 9.1.5/PHP 8.4 and 8.5; lifecycle on 9.0.0 and 9.1.5 | PS 8.0.5/PHP 7.2, 8.2.8/PHP 7.2 and 8.1; lifecycle on 8.0.0/PHP 7.2 and 8.2.8/PHP 8.1 | PS 1.7.8.0/PHP 7.1 and 1.7.8.11/PHP 7.1/7.4; lifecycle cross-product of both versions and both PHP versions |
| Packaging policy | Confirmed | Confirmed | Confirmed |
| RC version mapping | `v3.0.0-rc.1` to `3.0.0rc1`; migration discovered | `v2.0.0-rc.1` to `2.0.0rc1`; migration discovered | `v1.0.0-rc.1` to `1.0.0rc1`; migration discovered |
| Release workflow | Dry-run validation and publication-artifact checksum passed; publish skipped | Dry-run validation and publication-artifact checksum passed; publish skipped | Dry-run validation and publication-artifact checksum passed; publish skipped |

## Confirmed test results

### PS9

- Unit suite: 134 tests, 549 assertions, 3 database-only skips.
- Four static compatibility jobs passed, including PS 9.1.5 on PHP 8.5.
- Both lifecycle endpoints installed the same candidate and passed 70 HTTPS
  checks, the installed-listener Chrome check, 2 database tests with 30
  assertions, duplicate installation, and all five rollback stages.
- CI verifies the exact historical commits and their declared PS8-only range.

### PS8

- Unit suite: 149 tests, 723 assertions, 3 database-only skips.
- Both lifecycle endpoints installed the same candidate and passed 70 HTTPS
  checks, 25 installed-package browser checks, 2 database tests with 30
  assertions, both genuine historical upgrade paths, duplicate installation,
  and all five rollback stages.
- Historical sources are unchanged and pinned to:
  - 0.2.7: `9334de7296f98d4248af4b7b541038ad34da22cc`
  - 0.2.8: `bdd0ff970b327f8fea2c07eef6e5573e8fc33bf9`
- The final run supersedes the earlier candidate from run 34049858590 while
  retaining its topology and corrections.

### PS1.7

- Unit suite: 129 tests, 490 assertions.
- All four lifecycle combinations installed the same candidate and passed 114
  HTTPS checks, the installed-listener Chrome check, the database suite at 7
  tests and 194 assertions repeated 10 times, duplicate installation, and all
  five rollback stages.
- Runtime validation is on PHP 7.1 and 7.4. PHP 8.1 is used only as an isolated
  build environment for dependency scoping; the resulting archive is tested on
  the lower runtime versions.
- CI verifies the exact historical commits and their declared PS8-only range.

## Security and cross-branch findings

The following fixes are confirmed on every affected line:

- UTC-safe rate-limit expiry and alert elapsed-time handling.
- Password-confirmation throttling and audit records.
- Server-selected challenge scope.
- Complete request-field aggregation before sensitive-action gating.
- Native profile permissions, SuperAdmin hierarchy checks, CSRF in POST bodies,
  and denial auditing for factor reset and enrollment approval.
- Non-destructive duplicate installation.
- XHR and fetch recovery without same-form enrollment/challenge reload loops.
- Generated and masked disposable credentials; no fixed test password was added.
- Private vulnerability reporting and accurate security-reporting instructions.

The branches retain separate controller, service-container, and compatibility
implementations where their PrestaShop APIs differ. The review compared final
behavior and unique commits rather than merging these implementations blindly.

## Packaging and release workflow

Every builder now isolates build-only dependencies, excludes generated
`config.xml`, excludes development material, and scopes runtime dependencies.
Archive validation permits only the intentional root `vendor/autoload.php`
bridge to `vendor-scoped/autoload.php`; unscoped dependencies and nested
dependency metadata are rejected.

The tag workflows accept only their own major (`v3.*`, `v2.*`, or `v1.*`). A
manual dry run invokes the same reusable CI with the intended RC name, downloads
the resulting candidate, and verifies its checksum. The publish job runs only
for a tag-push event and was skipped in all reviewed runs. The final
`gh release create` call remains intentionally unexecuted.

## Prioritized remaining work

1. **P1 — complete native browser journeys on PS9 and PS1.7 (#39).** Drive
   enrollment, challenge, recovery/replacement, delegated permissions, and
   step-up through the installed Back Office UI. Current HTTP and Chrome tests
   prove the pieces separately, not the full browser journey.
2. **P1 — finish the cross-version timezone corpus (#28).** PS9 and PS8 have
   PHP/SQL timezone matrices and DST alert checks. PS1.7 still needs the same
   extreme-timezone and SQL-session matrix, and the broader database/PHP seam
   list remains incomplete.
3. **P1 — finish deterministic hardening gates (#29).** The release work added
   several concrete gates, but the single `composer harden` entry point,
   exhaustive forbidden-pattern checks, route/audit completeness checks, and
   agreed static-analysis gate are not complete.
4. **P2 — make explicit security/product decisions.** Address or deliberately
   defer absolute verified-session lifetime (#6), alert-delivery failure audit
   visibility (#10), flexible recovery-code entry (#11), recovery-code plaintext
   lifetime (#12), the pre-challenge allow-list asymmetry (#13), backslash/control
   rejection in return targets (#14), and legacy container failure handling
   (#23).
5. **P2 — stable-release maintenance.** Static analysis (#17), author metadata
   (#18), duplicate/dead key initialization (#20), request-local key memoization
   (#21), translation catalogs (#24), and documentation cleanup (#27) remain.

Issue #1 is a broad threat model rather than a verified single defect. Its
recommendations should be evaluated as product-policy work; they are not all
claims about the current implementation.

## Confirmed versus untested

Confirmed claims above come from executable checks at the exact tested commits
and from archive checksum verification. The following remain assumptions or
deliberately unexecuted actions:

- PS9 and PS1.7 full native browser journeys are not yet tested end to end.
- Historical upgrades on PS9 and PS1.7 are not claimed; changing the original
  PS8-only packages to force such a test would make the evidence false.
- The actual GitHub publication command was not run. The artifact handoff and
  checksum path were tested in dry-run mode.
- No tag, GitHub release, or external notification was created.
