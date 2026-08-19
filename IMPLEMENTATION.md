# Implemented architecture

This document describes the PrestaShop 9 implementation in this work-in-progress branch. The older `spec.md` and `HANDOFF.md` files are historical decision records and are not normative.

## Authentication

The module supports TOTP and single-use recovery codes only. Login enforcement defaults to SuperAdmins and can be extended to selected profiles or every employee.

Enrollment requires HTTPS and confirmation with the first valid TOTP. SuperAdmins and configured high-risk profiles require approval by another freshly verified SuperAdmin after the first SuperAdmin is bootstrapped. QR codes are generated locally.

Recovery codes are shown once and require explicit server-validated acknowledgement. A used code is consumed under a database lock and creates a restricted session that permits authenticator replacement or logout only. Replacing an authenticator, disabling an optional factor, or regenerating the entire recovery set requires a fresh TOTP plus a password step; a recent native login satisfies the password step for the configured maximum age. Policy-required factors cannot be self-disabled.

Administrative resets require another freshly verified SuperAdmin and cannot target the actor. A dedicated CLI reset provides break-glass recovery.

## TOTP verification

`pragmarx/google2fa:^9.0` is used directly with SHA-1, six digits, a 30-second period, 160-bit secrets and a ±1-period window. The adapter calls `verifyKeyNewer()`. The accepted counter advances with an atomic database comparison so concurrent requests cannot accept the same time step twice.

Rate limits are persisted by scope and a SHA-256 subject derived from employee and IP. Progressive delays begin after five failures and grow to one hour.

## Envelope encryption

Installation generates a random Defuse data-encryption key. `KeyProtectedByPassword` wraps the DEK with `_NEW_COOKIE_KEY_`; only that representation and a non-secret fingerprint are stored in the keyring.

TOTP secrets are encrypted directly with Defuse `Crypto` and reference a keyring version. Plaintext is discarded after enrollment. Missing keys, wrong cookie keys, damaged wrappers and altered ciphertexts fail closed.

Key rotation is two phase: prepare with `MP2FA_NEW_COOKIE_KEY`, change PrestaShop's `_NEW_COOKIE_KEY_`, then commit. Only the DEK wrapper changes; employee ciphertexts are not rewritten.

## Step-up protection

Unsafe supported module and theme mutation routes require a fresh MFA verification, five minutes by default. Covered flows include module upload/import, install, upgrade, enable, disable, reset, delete or uninstall, bulk/update-all operations, and theme import or enable. MFA policy updates, enrollment approvals and administrative resets are also protected.

The challenge runs before controller execution. The user returns to a safe page and must submit the mutation again. Uploaded files and POST bodies are never saved, queued or replayed. Integration uses the admin Symfony kernel and generation-specific route adapters without core patches.

## Operations and release

Module tables hold the keyring, factor state, recovery hashes, enrollment approvals, rate limits and audit events globally across multistore installations. Alerts go to the affected employee and configured recipients. Audit retention defaults to 90 days. Confirmed uninstall removes all module data and configuration.

The scoped build emits `SBOM.json` and `SHA256SUMS` and rejects unprefixed dependency namespaces. The current `3.x` target is PrestaShop 9/PHP 8.1+. PrestaShop 8 and 1.7 compatibility lines remain future work.

Focused unit tests cover RFC 4226/6238 vectors, window handling, malformed input, replay rejection, local QR output, recovery-code primitives, and Defuse tamper/wrong-key failures. Live PrestaShop 9 Docker testing covers install/uninstall cleanup, enrollment, recovery acknowledgement, login challenge, TOTP replay, recovery restriction, single-use recovery codes, audit/rate-limit persistence, key health, and the scoped release runtime. Stable release still requires the exhaustive PrestaShop/PHP compatibility matrix and review described in the release plan.
