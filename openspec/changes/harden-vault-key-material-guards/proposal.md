## Why

Issue #395 (`ConductionNL/keepiq`, 2026-08-21) reports that an attacker holding **only an authenticated Nextcloud session** for a Keepiq user can permanently destroy that user's access to their entire vault — including their pre-arranged emergency-access recovery — without learning a single secret.

The asymmetry is the point. Keepiq is zero-knowledge: a session alone does not let anyone read the vault, because decryption needs the master password, which the server never holds. A stolen session is therefore normally *not* game-over. These paths turn it into one, for destruction rather than disclosure.

Stated as the invariant that is currently violated:

> Reading the vault requires the master password. Destroying it requires a cookie.

Re-verified on `development` @ `c1cac29c`, four independent paths reach permanent loss from a session alone:

| Path | Effect | Reversible today | Gate today |
|---|---|---|---|
| `PUT /api/v1/suites/{id}/private-key` | overwrites the private-key envelope in place | only via emergency access | ownership |
| `POST /api/v1/suites/compromise-recovery` | mints a successor suite under attacker-supplied key material, write-locks the vault | **no — no abort route exists** | ownership |
| `POST /api/v1/migrations/{id}/secrets/{secretId}` | writes client-supplied ciphertext verbatim over the original | no | ownership |
| `POST /api/v1/migrations/{id}/complete` | marks the old suite `compromised`, invalidates emergency access | no | acknowledgement count (see below) |
| `DELETE /api/v1/emergency-access/contacts/{id}` | deletes the recovery envelope — the only survivor of row 1 | no | ownership |

Three findings beyond the filed issue, established while tracing it:

1. **`updatePrivateKey` is a one-request version of the same lockout.** Its only gate is `validateOwnership()`. Posting a garbage envelope means the master password no longer decrypts anything, and the private key existed *only* as that envelope. Fewer steps than the filed chain, no acknowledgement, no audit trail, and no write-lock guard — so it works mid-migration too.
2. **The acknowledgement gate in `complete()` can be bypassed entirely.** The filed chain reports per-record failures, which forces the `acceptUnrecoverable` handshake. An attacker need not: `reEncryptSecret()` accepts client-supplied ciphertext and `commitSecret()` writes it verbatim, and the round-trip verification the spec names is performed in the *browser* — the server structurally cannot repeat it under ADR-003. Committing garbage for every record yields zero failures, so completion succeeds with no acknowledgement at all. **Hardening `complete()` therefore does not close the hole; the gate has to be at the entry to rotation.**
3. **The safety net is removable by the same authority.** `EmergencyAccessController::destroy()` is session-only, so the attack sequence is *delete the emergency contacts, then lock out*.

Two facts make this cheap to fix correctly rather than expensively:

- **The spec already assumes the gate exists.** `encryption-suites` -> *Master Password Change — Compromise Recovery* reads "AND provides their old master password and a new master password". The old password *is* collected; it is consumed entirely client-side, so the server never observes any consequence of it. This change does not introduce new policy — it makes the server able to verify what the spec already claims.
- **The client-side half is already written.** `src/crypto/reauth.js` implements master-password re-authentication and documents its own limitation: *"a 're-auth' gate is a CLIENT-SIDE proof of knowledge... The control is advisory against a tampered client."* It is already used by `AccountDeletionDialog.vue:199`, `CxpTransferDialog.vue:365` and `ExportDialog.vue:349`. The upgrade is a return type: a boolean the client consumes becomes a signature the **server** verifies.

`#392` (`d475d00d`, *refuse a plain create when the owner already has an active suite*) closed one milder instance of the same theme and does not address any path above.

## What Changes

- Introduce a reusable, attribute-driven guard — `#[VaultKeyProofRequired]` plus `VaultKeyProofMiddleware` — that refuses a request unless it carries a signature, made with the private key of the owner's EncryptionSuite, over a server-issued challenge bound to the operation's own parameters. Because the private key is only obtainable by decrypting its envelope with the master password, this is a server-verifiable proof of the master password
- Add a challenge endpoint (`GET /api/v1/suites/{id}/proof-challenge`) issuing a stateless, expiring, HMAC-authenticated nonce
- Apply the guard to `compromiseRecovery`, `updatePrivateKey`, `complete` and the emergency-contact `destroy` route
- Add the **abort** route that `compromiseRecovery()`'s own error message already promises ("Resume or **abort** that migration before starting another") but which does not exist in `appinfo/routes.php` or `MigrationController`. Abort is permitted only while no record has been committed, releases the write lock, revokes the unused successor suite, and leaves the old suite `active`
- Add an attribute-coverage test in the shape of the existing `RateLimitAttributesTest`, asserting every route on the destructive list carries the guard — so a future destructive route that forgets it fails the build rather than failing open
- Extend `src/crypto/reauth.js` with `proveMasterPassword()`, returning a signature instead of a boolean, and wire the four guarded flows to fetch a challenge and send the proof header

Explicitly **not** in scope: retrofitting the three existing advisory `verifyMasterPassword()` call sites (export, CXP transfer, account deletion) onto the middleware. That is a clean follow-up once the guard exists, and folding it in here would roughly double the diff for an unrelated concern (see AGENTS.md on PR size).

## Capabilities

### New Capabilities
- `vault-key-proof`: A server-verified proof of master-password knowledge, expressed as a signature over a server-issued challenge made with the owner's suite private key, applied declaratively to controller methods via a PHP attribute and enforced by app middleware. Covers challenge issuance and expiry, the binding of a proof to the parameters of the operation it authorises, the signature-over-decryption requirement, and the fail-closed coverage guarantee

### Modified Capabilities
- `encryption-suites`: compromise recovery and private-key replacement require a verified key proof; a migration gains an abort terminal state and the route that reaches it; the "always has a way to terminate" requirement gains the abort escape it currently lacks
- `emergency-access`: deleting an emergency contact requires a verified key proof, since it destroys the only recovery path that survives a private-key overwrite

## Impact

- **Database**: none. `SuiteMigration::$status` is a plain `string` column (`lib/Db/SuiteMigration.php:115`), so the new `aborted` terminal value needs no schema change — and therefore no migration and no `<version>` bump for gate-110. The stateless nonce design adds no table
- **Backend**: new `lib/Attribute/VaultKeyProofRequired.php`, `lib/Middleware/VaultKeyProofMiddleware.php`, `lib/Service/VaultKeyProofService.php`; new `abort` action on `MigrationController` and `SuiteMigrationAbortedEvent`; challenge endpoint on `EncryptionSuiteController`; middleware registered in `PlatformIntegrationRegistrar` alongside the existing `JwtAuthMiddleware`
- **Frontend**: `src/crypto/reauth.js` gains `proveMasterPassword()`; `CompromiseRecoveryForm.vue`, the routine password-change flow, the emergency-contact delete action and the migration-completion call each fetch a challenge and send the proof header; a new abort control on `MigrationResumeBanner.vue`
- **API**: two new endpoints (`proof-challenge`, `abort`); four existing routes begin requiring the `X-Keepiq-Key-Proof` header and answer `403 {"error": "key_proof_required"}` without it. Breaking for any client of those four routes — acceptable and deliberate while the app carries its pre-production disclaimers
- **Security**: this is the whole point of the change. The guard resists a stolen session, a leaked app password, and XSS in an *already-unlocked* tab — the last because the session `CryptoKey` is imported non-extractable and `['decrypt']`-only (`src/crypto/rsa.js:61-66`), so it cannot produce a signature. See `design.md` D2, which is load-bearing and must not be "simplified" to a decrypt-based challenge
- **Cross-app**: none. Every guarded route is session-authenticated (`#[NoAdminRequired]`, owner derived from `IUserSession`). Application-owned suites hold no server-side envelope at all (`EncryptionSuiteProvisioningService` stores `encryptedPrivateKey: ''`) and authenticate via `JwtAuthMiddleware` on `ApplicationApiController` routes, which this change does not touch. OpenConnector is unaffected
