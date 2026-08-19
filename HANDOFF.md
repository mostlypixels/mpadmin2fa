# Handoff — TOTP secret encryption, for external review

Written for a reviewer with no access to this repository. Self-contained: everything needed
to judge the decision is below. No credentials or key material appear in this file, and none
should be added to it.

## Context

`mpadmin2fa` is a PrestaShop 9.3 module adding two-factor authentication to **back-office
(employee) login**. Full functional spec in [spec.md](spec.md); nothing is implemented yet.

Relevant platform facts, verified against the 9.3 source:

- Back-office authentication is a Symfony `form_login` firewall (`app/config/admin/security.yml`),
  provider `prestashop.security.admin.provider`. Remember-me is enabled with
  `signature_properties: ['password']`.
- A module can register services into the admin kernel via `config/admin/services.yml`
  (loaded by `PrestaShopBundle\DependencyInjection\Compiler\LoadServicesFromModulesPass`),
  so an event subscriber can gate requests behind the firewall.
- Core ships `PhpEncryption` (a wrapper over Defuse crypto, hex key in, authenticated
  ciphertext out) and keeps secrets in `app/config/parameters.php` on the filesystem —
  `secret`, `cookie_key`, `new_cookie_key`, `api_public_key`, `api_private_key`.
- Shop configuration values live in the `ps_configuration` **database** table.

## The decision

Of the module's stored credentials, only the TOTP shared secret must be **reversible** —
verifying a code means recomputing HMAC-SHA1 over the raw secret. Recovery codes and emailed
codes are one-way hashed (`password_hash`) and are not part of this question.

So TOTP secrets are encrypted at rest, and the security of that rests entirely on **where the
encryption key lives**. Threat model: an attacker who obtains a database dump — SQL injection,
a stolen backup, a compromised replica — and not the application filesystem. If the key sits in
the same dump as the ciphertext, the encryption buys nothing.

### Options considered

| # | Key source | Key location | Assessment |
|---|-----------|--------------|------------|
| 1 | `_NEW_COOKIE_KEY_` through core's `PhpEncryption` | `app/config/parameters.php` (filesystem) | Correct threat separation, no new machinery. Risk: core treats cookie keys as rotatable — `new_cookie_key` exists beside `cookie_key` precisely because they get rotated — and a rotation silently makes every stored secret undecryptable |
| 2 | `%kernel.secret%` | same file | Same location, but that secret already signs remember-me and CSRF. One secret serving several unrelated purposes widens the blast radius of any disclosure |
| 3 | Module-owned key generated at install, written to a file | e.g. `app/config/mpadmin2fa.key` (filesystem) | Same threat separation as 1, and rotation is on the module's schedule rather than core's. Costs a file-permission and backup story, and a deployment that must carry the file |
| 4 | Key stored in `ps_configuration` | database | Rejected — key and ciphertext leak together |

### Recommendation

**Option 3**, using core's `PhpEncryption` for the cryptography itself rather than hand-rolled
`openssl_*` calls. Rationale: independence from core key rotation is worth one file on disk,
and the failure mode of option 1 is silent — enrolments keep verifying until the day a core
key rotation breaks every one of them at once.

Consequences to accept either way:

- Key loss means every employee must re-enrol. This needs a documented operator procedure and
  a supported "clear all enrolments" path, not just an incident.
- The key file must be excluded from backups that travel with the database dump, or the
  separation is undone in practice.
- The choice sits behind a single-method interface in `src/` (encrypt/decrypt of the secret),
  so switching sources later is a service swap and never a schema change.

## What the review should check

1. Is the threat model right for a typical PrestaShop deployment — is database-only compromise
   the case worth designing against, or is filesystem-plus-database the realistic breach, which
   would make all of options 1–3 equivalent and argue for spending the effort elsewhere?
2. Does option 3's independence actually beat option 1's simplicity, given someone has to
   operate the key file for the module's lifetime?
3. Is there a fifth source worth having — envelope encryption, an external KMS/HSM, or deriving
   the key from the employee's password so that even the application cannot decrypt at rest?
   Note the last one breaks emailed-code and admin-reset flows; is that trade worth examining?
4. Anything wrong with using Defuse via core's `PhpEncryption` for this, as opposed to
   libsodium/`sodium_crypto_secretbox`?

## Other open questions in the spec

Secondary to the above, listed so a reviewer sees the whole picture:

- Remember-me is configured with `signature_properties: ['password']`. Does `LoginSuccessEvent`
  fire on cookie-based re-authentication, or must the challenge gate on a session flag alone?
  A miss here is a full 2FA bypass.
- Should the challenge apply to every entry point sharing the admin firewall, or only browser
  sessions?
- Trusted-device support ("don't ask again on this browser for 30 days") — worth having, or
  does it undercut the point?
- TOTP implementation: `spomky-labs/otphp` (maintained, adds a dependency) or roughly 50 lines
  of in-module RFC 6238?
