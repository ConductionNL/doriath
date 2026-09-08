## Context

Keepiq's always-E2E architecture (ADR-003, encryption-suites spec) means the server cannot produce a readable export and cannot verify a master password — both export modes must run in the browser, where the decrypted vault is available once the user has unlocked. Conversely, *deletion* is a server-side concern with cross-user blast radius: the user-sharing spec makes recipient share-copies full `Secret` rows encrypted under the recipient's own suite (linked by `SecretShare`, kept in sync), introduces `SecretDelegation` with `is_permanent = true` defined as "owner's suite was revoked/deleted", and link shares + secret requests hold further references into the user's data.

FEATURES.md promises at V1: secret export (encrypted backup, CSV), GDPR export, GDPR deletion, and an audit trail. The audit trail is unbuilt and not in flight (`add-secret-audit-trail` is the suggested future change), so this change emits the events and deliberately does not build storage or UI for them.

## Goals / Non-Goals

**Goals:**
- Encrypted backup export (`.doriath-backup`) generated fully client-side, round-trippable via `secret-import`
- Plaintext CSV export behind explicit re-auth + warning, compatible with the generic CSV import mapping
- Export scope selection (whole vault / selected folders)
- GDPR Art. 15 export: one machine-readable package = server metadata + client-decrypted vault
- GDPR Art. 17 deletion: in-app flow + automatic `UserDeletedEvent` cascade, with defined semantics for shared secrets (ownership transfer or detach-with-tombstone)
- Typed export/deletion events for the future audit-trail change

**Non-Goals:**
- Audit-trail storage, retention, or admin UI (future `add-secret-audit-trail`; this change only emits events)
- Emergency access / designated-survivor flows (separate design discussion; FEATURES risk table)
- Export to PDF (Enterprise tier per FEATURES.md)
- Scheduled/automatic backups (one-shot, user-initiated only in v1)
- Admin-initiated export of another user's vault (cryptographically impossible by design — worth stating)
- Application-vault (`owner_type = application`) export/deletion — application lifecycle is owned by implement-application-mgmt's cascade
- Selective GDPR erasure of individual secrets (ordinary delete already covers it; Art. 17 here means account-level erasure)

## Decisions

### D1: Encrypted Backup Format — Argon2id + AES-256-GCM, Versioned JSON

The backup is a JSON envelope:

```json
{
  "format": "doriath-backup",
  "version": 1,
  "created_at": "2026-06-11T12:00:00Z",
  "kdf": { "alg": "argon2id", "memory": 65536, "iterations": 3, "parallelism": 1, "salt": "<base64 16B>" },
  "cipher": { "alg": "aes-256-gcm" },
  "payload": "<base64: [12B IV][ciphertext+tag]>"
}
```

The plaintext payload (before encryption) is `{ secrets: [...], folders: [...] }` with full decrypted fields, folder paths, and types. KDF parameters are **stored in the envelope** (unlike link shares, which hardcode them) so future parameter bumps don't break old backups.

The user chooses a backup passphrase (zxcvbn ≥ 3 floor, same meter component as the master password). The browser decrypts the vault with the in-session CryptoKey, serializes, derives the AES key via the existing `src/crypto/argon2.js` module (from implement-link-sharing), encrypts, and triggers a local Blob download. **No part of this flow touches the server** except the export-event call (D5).

**Why reuse the link-share crypto module:** identical KDF + cipher needs, already tested, already bundles the WASM. **Why not encrypt to the user's own RSA suite:** a backup must survive exactly the scenario where the suite is lost (forgotten master password, compromised/revoked suite, instance gone) — it has to be independently decryptable from the passphrase alone. This is also the pragmatic v1 mitigation for FEATURES.md's "master password lost = data lost" High risk.

**Round-trip:** `secret-import` gains a `doriath-backup` parser path as part of *its* format set? No — to keep each change self-contained, the **backup restore parser ships in this change** as a registered format module in the import wizard's parser registry (the registry is `secret-import`'s extension point). Restore = passphrase prompt → client-side decrypt → normal import pipeline (mapping is fixed, duplicates handled by the standard step).

### D2: Plaintext CSV Export — Re-Auth Proof That Stays Client-Side

CSV export produces the obvious plaintext columns (`name,url,login,password,notes,folder,type`) — compatible with the `secret-import` generic CSV auto-detection — but is gated by:

1. An explicit warning dialog stating the file is unencrypted, lists every credential, and should be deleted after use
2. **Fresh master-password re-entry**, even when the vault is already unlocked

Because the server cannot verify the master password (ADR-003 — it never sees it), re-auth is a client-side proof of knowledge: the entered password is run through the normal AES key-derivation and used to decrypt the stored private-key blob (re-fetched from the API); only if that succeeds does the export proceed. The freshly derived key is discarded immediately — the session CryptoKey is not replaced. This blocks the lunch-break attack (unlocked unattended session → silent full-plaintext dump) without weakening any E2E guarantee.

**Why CSV at all:** data portability to tools whose import paths are CSV-only; FEATURES.md names it explicitly. The warning + re-auth follows the pattern every major manager (Bitwarden, 1Password) uses for plaintext export.

### D3: GDPR Art. 15 Export — Server Metadata + Client Vault, Assembled in the Browser

Art. 15 covers *all* personal data, not just secret values. Two halves:

- **Server half** — `GET /api/v1/gdpr/metadata` returns everything Keepiq stores about the user that is readable server-side: EncryptionSuite records (certificate, status, audit fields — the encrypted private-key blob is **excluded**: it is unreadable to the data subject without the master password they already hold, and shipping it in an otherwise-unprotected JSON file only widens the attack surface; the exclusion and rationale are documented inside the package itself), CA-issued certificate DNs, shares given and received (counterparty user IDs included — they are part of the data subject's data), delegations, link-share metadata (no snapshots), secret requests, user settings.
- **Client half** — the decrypted vault (same serializer as D1's payload).

The browser merges both into one `keepiq-gdpr-export.json` (versioned, self-describing, field-documented) and downloads it locally. The vault half requires an unlocked session; if the user cannot unlock (lost master password), the package contains the server half only, with the vault section marked `"unavailable": "vault is end-to-end encrypted and the data subject did not unlock it"` — which is itself the honest Art. 15 answer.

**Why browser-assembled:** the server physically cannot produce the readable vault half; shipping two separate files confuses the "one package" expectation auditors have.

### D4: Account Data Deletion — Cascade Order and Shared-Secret Semantics

Two triggers, one implementation (`AccountDeletionService::deleteAllFor(userId)`):

- **In-app** (`DELETE /api/v1/gdpr/account-data`): master-password re-auth (as D2) + typed confirmation phrase. Deletes Keepiq data; the Nextcloud account remains.
- **Automatic**: a registered `OCP\User\Events\UserDeletedEvent` listener runs the same cascade when the Nextcloud account is removed, so Keepiq data can never outlive its account.

Cascade order (each step idempotent, the whole run resumable):

1. **Delegated secrets — ownership transfer.** Secrets with an active `SecretDelegation` transfer to the delegate: the delegation flips to `is_permanent = true` (the user-sharing spec already defines permanent delegation as covering "owner's suite was deleted"), the secret's `owner_id` becomes the delegate, and the delegate's copy/access continues uninterrupted. The departing user's identity is removed from the delegation record's subject fields where retained.
2. **Shared secrets — detach with tombstone.** For every `SecretShare` where the deleted user is the *sharer*: the recipient's copy is already a full `Secret` row encrypted under the recipient's suite, so the recipient keeps it as an independent secret. The `SecretShare` link row is deleted (sync severed), and the recipient copy gets `tombstoned_at = now`, `tombstone_reason = 'owner-account-deleted'` — **no user ID, display name, or other personal data of the deleted user is retained**. The UI renders this as "shared by a deleted account; no longer synced". GroupShares created by the user are cascade-deleted the same way (each member copy detached).
3. **Received shares.** `SecretShare` rows where the deleted user is the *recipient*: the recipient copy (a row in the deleted user's vault) and the link row are hard-deleted. The original owner's secret is untouched.
4. **Link shares, secret requests**: `deleteByUserId` cascades (methods exist from implement-link-sharing / implement-secret-requests).
5. **Own secrets and folders**: hard delete of all remaining rows owned by the user.
6. **EncryptionSuites**: hard delete of the user's suite rows (certificate + encrypted private key) and their `SuiteMigration` records. Issued certificates are not retroactively scrubbed from CA history — the CA chain is instance infrastructure; the *suite* rows holding the user's keys are what go.
7. **Settings / preferences**: deleted.
8. Emit `AccountDataDeletedEvent` (D5).

**Why ownership transfer only via existing delegation:** automatically picking a recipient to "inherit" an account's secrets would silently move credentials to someone the owner never chose as a successor. Delegation is Keepiq's existing, explicit successor mechanism; deletion respects it and otherwise defaults to the conservative detach-with-tombstone. **Why tombstone instead of deleting recipient copies:** the recipient's copy is data the recipient legitimately holds (it was shared with them, it is encrypted under *their* key, they may depend on the credential operationally). Destroying it would let one user's account deletion break other users' access with zero notice — and GDPR erasure does not require destroying other parties' copies of information legitimately disclosed to them; it requires erasing the *subject's* data, which steps 3–7 do.

### D5: Audit Events — Emit Now, Store Later

Three typed events in `lib/Event/`, dispatched via `OCP\EventDispatcher\IEventDispatcher`:

| Event | Dispatched by | Payload |
|---|---|---|
| `SecretExportedEvent` | ExportController (`POST /api/v1/export/events`) | userId, mode (`encrypted-backup` \| `plaintext-csv`), scope (`vault` \| `folders`), secretCount, timestamp |
| `GdprExportPerformedEvent` | ExportController (metadata endpoint + event call) | userId, includesVault (bool), timestamp |
| `AccountDataDeletedEvent` | AccountDeletionService | userId, trigger (`in-app` \| `user-deleted`), per-entity counts (secrets, shares transferred/detached/removed, link shares, requests, suites), timestamp |

Payloads carry **counts and modes only — never secret names, values, or ciphertext**.

Because export runs client-side, the server only learns about it when told: the export flow MUST call the event endpoint **before** offering the file download, and the endpoint emits the event for the session user only. This is honest-client accountability — a malicious client can already read every secret through the normal API without any export UI, so the event covers the supported flows, which is exactly what an audit trail of UI operations can ever promise under E2E. This limitation is stated in the spec rather than papered over.

**Why events and not a table:** FEATURES.md's audit trail is a V1 feature of its own (`add-secret-audit-trail`) with retention, admin UI, and scope decisions this change must not preempt. Typed events are the stable contract: when the audit change lands, it registers listeners and gets export/deletion coverage retroactively-free. Until then, ops can attach a generic event logger if needed.

### D6: Tombstone Columns, Not a Tombstone Table

`tombstoned_at` (datetime, nullable) + `tombstone_reason` (string enum-ish, nullable) on `keepiq_secrets` via ISchemaWrapper migration. A detached copy is an ordinary secret the recipient fully owns; two nullable columns let the UI badge it and let future cleanup policies find it, without a join table that would outlive its purpose. Tombstone fields are display metadata only — they impose no access restrictions.

## Risks / Trade-offs

- **[Risk] Plaintext CSV lands in Downloads and stays there** — inherent to the feature; mitigated by the warning dialog's explicit "delete after use" instruction and by making encrypted backup the visually-primary option in the export dialog. We do not implement auto-deleting files (impossible from a browser).
- **[Risk] Backup passphrase forgotten** — the backup becomes undecryptable; this is the same zero-knowledge property as the vault itself. Mitigated by the strength meter's accompanying hint text recommending a written-down passphrase for backups (different threat model than a daily-use master password).
- **[Risk] Client-side re-auth is advisory against a tampered client** — true of every client-side control under E2E (the session CryptoKey already grants full read). The control targets the realistic threat (unattended unlocked session), not a compromised browser. Stated openly in D2/D5.
- **[Risk] Deletion cascade partially fails** (e.g. crash mid-run) — every step is idempotent and keyed by userId; the `UserDeletedEvent` listener re-runs safely, and the in-app flow can be retried. The event (D5) is emitted only on completed runs.
- **[Trade-off] Detached recipients lose sync silently-ish** — recipients get the tombstone badge but no push notification in v1 (notification fan-out for mass detach on account deletion could spam hundreds of users). Revisit when the fleet notification engine integration lands.
- **[Trade-off] GDPR export without unlock is metadata-only** — unavoidable under E2E; the package says so explicitly, which is the defensible Art. 15 posture.

## Migration Plan

1. **Database migration**: ISchemaWrapper migration adding `tombstoned_at` + `tombstone_reason` to `keepiq_secrets` (next free version number at implementation time); `occ upgrade`
2. **Event listener registration**: `UserDeletedEvent` listener registered in `Application::register()`
3. **Frontend build**: no new dependencies (Argon2id WASM already present from link sharing); `npm run build`
4. **Rollback**: disable endpoints/listener; tombstone columns are inert nullable metadata
5. **Greenfield**: no existing export/deletion data to migrate

## Open Questions

- Should detached recipient copies eventually expire (auto-delete N days after tombstoning)? Current decision: no — the credential is the recipient's working data; expiry policies belong to the Enterprise retention-policy tier in FEATURES.md.
- Should the in-app deletion flow offer "export first" inline? Current decision: yes as a non-blocking suggestion link in the confirmation dialog (cheap, prevents most regret), but not as a mandatory step.
- Nextcloud also ships a platform-level GDPR export hook (user_migration / data export apps). v1 ships Keepiq's own package; wiring `IMigrator` support so Keepiq data joins the platform-wide export bundle is a candidate follow-up — noted, not scoped, because the vault half cannot be produced without the user's browser anyway.
