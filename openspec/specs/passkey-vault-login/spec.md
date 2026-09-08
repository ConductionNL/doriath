# Passkey Vault Login Specification

**Status**: done

**Standards**: WebAuthn Level 2 (`prf` extension / CTAP2 `hmac-secret`), WebCrypto (HKDF-SHA256, AES-256-GCM)
**Feature tier**: V1

**OpenSpec changes:** [passkey-vault-login](../../changes/archive/2026-07-20-passkey-vault-login/)

## Purpose

Let a Keepiq user unlock their vault passwordlessly with a platform or roaming
WebAuthn authenticator, using the authenticator's PRF output to derive a
key-encryption key that wraps a copy of the vault unlock key — an alternative
unlock envelope stored alongside the canonical master-password envelope. The
master password stays canonical and always available as a fallback, so losing the
authenticator loses no data. The design preserves ADR-003's always-E2E,
zero-knowledge model: the server never sees the master password, the plaintext
unlock key, or the raw PRF output.

Competitive context: Bitwarden is the only vendor in the category with shipped
passkey vault-login (`docs/FEATURES.md:494`), and passkeys are now a tier-1
Nextcloud-platform expectation (`docs/FEATURES.md:499`). Canonical feature
`passkey-vault-login`, Spectr demand 60, competitorCoverage 1 — open frontier.

## Requirements

### Requirement: Passkey enrollment requires an unlocked vault
The system MUST require an unlocked vault to enroll a passkey and MUST store only
the credential metadata plus a client-side-produced wrapped copy of the vault
unlock key — never the master password, the plaintext unlock key, or the raw PRF
output.

#### Scenario: Enrollment while unlocked stores a wrapped envelope
- GIVEN a user with an unlocked vault and a PRF-capable authenticator
- WHEN they enroll a passkey
- THEN the system MUST persist a credential record plus an AES-256-GCM envelope
  wrapping the vault unlock key, and MUST NOT receive the master password, the
  plaintext unlock key, or the raw PRF output

### Requirement: Passwordless unlock derives the unlock key client-side
The system MUST unlock the vault from a WebAuthn assertion's PRF output —
deriving the KEK, unwrapping the vault unlock key, and importing a
non-extractable `CryptoKey` — without the master password being entered or sent
to the server.

#### Scenario: Passkey unlock opens the vault
- GIVEN a user with an enrolled, active passkey and a locked vault
- WHEN they complete the "Unlock with passkey" ceremony
- THEN the vault MUST reach the same unlocked state as a master-password unlock

### Requirement: Master password remains the canonical fallback
The system MUST keep the master-password unlock envelope intact and available at
all times; a lost or removed authenticator MUST NOT cause any loss of vault data
or access.

#### Scenario: Lost authenticator loses no data
- GIVEN a user whose only enrolled authenticator is lost
- WHEN they open the lock screen
- THEN they MUST be able to unlock with their master password with no secret lost

### Requirement: Passkeys are manageable, revocable, and owner-scoped
The system MUST let a user list and revoke their enrolled passkeys; revocation
MUST delete the server-side envelope; and all passkey endpoints MUST be
authenticated and reject cross-user credential access.

#### Scenario: Revocation deletes the unlock envelope
- GIVEN a user with an active enrolled passkey
- WHEN they revoke it
- THEN the server MUST delete its envelope and the authenticator MUST no longer
  be able to unlock the vault

### Requirement: PRF support is feature-detected and degrades gracefully
The system MUST feature-detect WebAuthn PRF availability at runtime and hide the
passkey option where unsupported, never blocking the master-password path.

#### Scenario: Unsupported browser shows only the master-password path
- GIVEN a browser or authenticator without PRF support
- WHEN the lock screen loads
- THEN the passkey option MUST NOT be offered and master-password unlock MUST work

### Requirement: Envelopes are invalidated when the unlock key changes
The system MUST mark passkey envelopes stale on a routine master-password change
and MUST delete all of an owner's passkey envelopes on a compromise-recovery
suite rotation.

#### Scenario: Compromise recovery deletes all passkey envelopes
- GIVEN a user with enrolled passkeys
- WHEN compromise recovery rotates their suite to a new RSA key pair
- THEN all their passkey envelopes MUST be deleted and re-enrollment MUST be
  required before passwordless unlock is available again

## User Stories

- As a user, I want to unlock my vault with Touch ID / a security key so that I
  do not have to type my master password every time
- As a user, I want my master password to keep working so that losing my
  authenticator never locks me out of my secrets
- As a user, I want to see and revoke my enrolled passkeys so that I control which
  devices can open my vault
- As a user on an unsupported browser, I want the app to just show me the
  master-password screen without errors

## Acceptance Criteria

- [ ] Enrollment requires an unlocked vault; the server never receives the master password, plaintext unlock key, or raw PRF output
- [ ] Passkey unlock reaches the identical unlocked end-state as a master-password unlock
- [ ] The master password is always offered and always works; a lost authenticator causes no data loss
- [ ] Revocation deletes the server-side envelope and removes the passkey unlock option for that authenticator
- [ ] PRF is feature-detected at runtime; unsupported browsers/authenticators see only the master-password path
- [ ] Routine master-password change marks passkey envelopes stale; compromise recovery deletes them all
- [ ] All passkey endpoints are `#[NoAdminRequired]` and reject cross-user credential access

## Notes

- New table `keepiq_passkey_credentials` (own Doctrine entity/migration per ADR-001 — no OpenRegister).
- KEK = HKDF-SHA256(prfOutput, salt=credentialId, info="keepiq-passkey-kek-v1"); envelope reuses `src/crypto/envelope.js` (AES-256-GCM).
- Staleness is tracked via an `unlock_key_epoch` on the private-key wrap.
- Related specs: encryption-suites (unlock/session, master-password change), user-settings (management surface). Related ADRs: ADR-001, ADR-003.
- Related change (composes later): offline-readonly-cache (offline passkey unlock is out of scope for v1).
