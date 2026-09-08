## 0. Dependency Note (read first)

This change depends on `implement-encryption-suites` (archived — CryptoKey
session, private-key blob API used for client-side re-auth),
`implement-secrets` / `implement-secrets-write-ui` (Secret/Folder entities,
services, stores, vault UI), `implement-user-sharing` (SecretShare /
GroupShare / SecretDelegation and its `is_permanent` semantics),
`implement-link-sharing` (the `src/crypto/argon2.js` WASM module reused for the
backup KDF, and `LinkShareService::deleteByUserId`), and
`implement-secret-requests` (request cascade method). The backup-restore parser
plugs into the import wizard's parser registry from `secret-import` — implement
that change first or land the registry stub here.

## 1. Database Migration

- [ ] 1.1 Create ISchemaWrapper migration (next free version number) adding `tombstoned_at` (datetime, nullable) and `tombstone_reason` (string 64, nullable) to `keepiq_secrets`; no index needed (display metadata only)
- [ ] 1.2 Extend the Secret entity + mapper with the two fields; include them in `jsonSerialize()` so the UI can badge detached copies

## 2. Backend — GDPR Metadata Export

- [ ] 2.1 Create `GdprService` in `lib/Service/GdprService.php` with `collectMetadata(userId)`: suites (certificate, status, audit fields — explicitly excluding the encrypted private-key blob, with the exclusion note embedded in the output), shares given/received, delegations, link-share metadata (no snapshots), secret requests, user settings
- [ ] 2.2 Create `GdprController` with `#[NoAdminRequired]` endpoint `GET /api/v1/gdpr/metadata`, scoped exclusively to the session user (no user parameter)
- [ ] 2.3 Version the metadata document (`format: keepiq-gdpr-metadata`, `version: 1`) with per-section field documentation

## 3. Backend — Account Deletion Cascade

- [ ] 3.1 Create `AccountDeletionService` in `lib/Service/AccountDeletionService.php` with `deleteAllFor(userId): DeletionReport` implementing the ordered, idempotent cascade: (1) delegation ownership transfer, (2) granted shares → detach recipient copies + tombstone, (3) received shares → hard delete copies + links, (4) link shares + secret requests via existing `deleteByUserId` methods, (5) own secrets + folders, (6) suites + SuiteMigration rows, (7) settings
- [ ] 3.2 Implement step 1: for each secret with an active SecretDelegation, set delegation `is_permanent = true`, reassign secret `owner_id` to the delegate, and scrub the deleted user from retained delegation subject fields
- [ ] 3.3 Implement step 2: delete SecretShare/GroupShare rows where the user is sharer; set `tombstoned_at`/`tombstone_reason = 'owner-account-deleted'` on each recipient copy; assert no personal data of the deleted user is written to the recipient copy
- [ ] 3.4 Implement steps 3–7 with per-step idempotency (each step keyed by userId, safe on re-run) and a `DeletionReport` accumulating per-entity counts
- [ ] 3.5 Create `lib/Event/AccountDataDeletedEvent.php`, `lib/Event/GdprExportPerformedEvent.php`, `lib/Event/SecretExportedEvent.php` (typed `OCP\EventDispatcher\Event` subclasses, payload = counts/modes/trigger/timestamp only)
- [ ] 3.6 Dispatch `AccountDataDeletedEvent` from `AccountDeletionService` only on completed runs
- [ ] 3.7 Create the `UserDeletedEvent` listener in `lib/Listener/UserDeletedListener.php` calling `deleteAllFor()`; register it in `Application::register()`
- [ ] 3.8 Create `DELETE /api/v1/gdpr/account-data` endpoint on `GdprController`: requires the typed confirmation phrase in the request body; returns the `DeletionReport` counts (the master-password re-auth is a client-side gate — document in the controller docblock why the server cannot verify it, per ADR-003)

## 4. Backend — Export Events Endpoint

- [ ] 4.1 Create `ExportController` with `#[NoAdminRequired]` endpoint `POST /api/v1/export/events` accepting `{mode, scope, secretCount}`; validates mode/scope enums; dispatches `SecretExportedEvent` for the session user
- [ ] 4.2 Dispatch `GdprExportPerformedEvent` from the GDPR metadata endpoint (with `includesVault` reported by the client in a query/body flag)
- [ ] 4.3 Register all new routes in `appinfo/routes.php` before the SPA catch-all wildcard; run hydra gates (route-auth, no-admin-idor, semantic-auth, spec-coverage)

## 5. Frontend — Export Serializers and Crypto

- [ ] 5.1 Create `src/export/serializer.js`: decrypted vault → versioned payload `{secrets, folders}` with relative folder paths; scope filter (whole vault / selected folder subtrees)
- [ ] 5.2 Create `src/export/backup.js`: backup envelope build (format/version/kdf params + salt/cipher/payload) using `deriveAesKeyArgon2id` from `src/crypto/argon2.js`; `encryptBackup(payload, passphrase)` and `decryptBackup(envelope, passphrase)` (reads KDF params from the envelope, not hardcoded)
- [ ] 5.3 Create `src/export/csv.js`: plaintext CSV generation (`name,url,login,password,notes,folder,type`) with RFC 4180 quoting, round-trippable through the secret-import generic CSV auto-detection
- [ ] 5.4 Create `src/export/gdprPackage.js`: merge server metadata + client vault payload into one versioned package; produce the metadata-only variant with the explicit "vault not unlocked" section
- [ ] 5.5 Implement client-side re-auth helper `src/crypto/reauth.js`: derive AES key from the entered master password, fetch and attempt decryption of the private-key blob, discard the derived key, return boolean — never replace the session CryptoKey

## 6. Frontend — Store and Dialogs

- [ ] 6.1 Create `src/store/modules/export.js` (`useExportStore`): actions `exportBackup(scope, passphrase)`, `exportCsv(scope, masterPassword)`, `exportGdprPackage(includeVault)`, `deleteAccountData(confirmationPhrase, masterPassword)`; each export action calls the export-events endpoint BEFORE triggering the local Blob download and surfaces endpoint failure; no plaintext/passphrase/key in any request body or persistent storage
- [ ] 6.2 Create `src/dialogs/ExportDialog.vue` (own file per ADR-004): mode choice (encrypted backup visually primary / plaintext CSV), scope selector (vault or folder NcSelect with `inputLabel`), backup-passphrase input with the zxcvbn strength meter (≥ 3 floor, submit disabled below it, hint recommending a written-down passphrase), plaintext path = warning NcNoteCard requiring acknowledgement → master-password re-entry → download
- [ ] 6.3 Create `src/dialogs/AccountDeletionDialog.vue`: consequence summary (counts fetched per entity), non-blocking "export first" suggestion link, typed confirmation phrase input, master-password re-entry, irreversibility warning; on success show the deletion report
- [ ] 6.4 Create `src/dialogs/GdprExportDialog.vue`: unlocked → full package; locked → offer unlock or metadata-only package with the explicit limitation text
- [ ] 6.5 Add entry points in user settings (Export data / GDPR export / Delete my Keepiq data) via the registry modal dispatch
- [ ] 6.6 Tombstone badge: render "Shared by a deleted account — no longer synced" on secrets with `tombstoned_at` in the list and detail views
- [ ] 6.7 Register the backup-restore parser (`.doriath-backup`: passphrase prompt → `decryptBackup` → normalized rows) in the import wizard's parser registry

## 7. Internationalization

- [ ] 7.1 Add English strings (export modes, warnings, passphrase hints, GDPR package texts, deletion confirmation flow, tombstone badge) to `l10n/en.json` — English source strings as keys
- [ ] 7.2 Add Dutch translations to `l10n/nl.json`

## 8. Unit Tests (PHP)

- [ ] 8.1 `GdprService::collectMetadata` tests: all sections present, private-key blob absent, exclusion note present, strictly self-scoped
- [ ] 8.2 `AccountDeletionService` tests per cascade step: delegation transfer (is_permanent flip + owner reassignment + subject scrub), granted-share detach (link deleted, tombstone set, no personal data on recipient copy), received-share removal (owner's secret untouched), link-share/request cascade delegation, suites + settings removal, report counts
- [ ] 8.3 Idempotency tests: running `deleteAllFor()` twice completes without error and without double-counting
- [ ] 8.4 Event tests: `AccountDataDeletedEvent` only on completed runs; payloads contain counts/modes only (assert no secret name/value/ciphertext fields exist on the event classes)
- [ ] 8.5 `UserDeletedListener` test: NC user deletion triggers the cascade
- [ ] 8.6 Controller tests: metadata endpoint self-scoped; account-data DELETE requires the confirmation phrase (400 without); export-events endpoint validates enums and dispatches for the session user only

## 9. Frontend Tests (vitest)

- [ ] 9.1 `backup.js` tests: envelope round-trip, KDF params read from envelope (decrypt succeeds after a simulated parameter bump in a v2 envelope), wrong-passphrase GCM failure (Argon2 WASM alias-stubbed as in `tests/vitest/argon2.spec.js`)
- [ ] 9.2 `csv.js` tests: RFC 4180 quoting/escaping; output parses cleanly through the secret-import CSV parser (round-trip fixture)
- [ ] 9.3 `gdprPackage.js` tests: full merge; metadata-only variant carries the limitation section
- [ ] 9.4 `useExportStore` tests: event endpoint called before download; endpoint failure surfaces and blocks silent skip; plaintext/passphrase never in request bodies; persistence-leak guard (no localStorage/sessionStorage writes)
- [ ] 9.5 Dialog component tests (jsdom harness): plaintext path enforces warning acknowledgement + re-auth before download; backup path blocks below the passphrase floor; deletion dialog blocks without the typed phrase
- [ ] 9.6 `reauth.js` test: wrong password returns false without touching the session CryptoKey

## 10. E2E (Playwright)

- [ ] 10.1 Encrypted backup e2e: unlock → export dialog → passphrase → download intercepted → restore via import wizard → secrets reappear (round-trip through the real Argon2 WASM in-browser)
- [ ] 10.2 Plaintext CSV e2e: warning acknowledgement → wrong master password blocked → correct password → CSV downloaded
- [ ] 10.3 Deletion e2e: seeded user runs in-app deletion (typed phrase + password) → vault empty → report shown
- [ ] 10.4 Annotate spec scenarios per gate-19 (`@e2e` refs for UI flows; `@e2e exclude` with reasons for server-only contracts: UserDeletedEvent cascade, idempotent re-run, event-payload shape — covered by PHPUnit)

## 11. Documentation

- [ ] 11.1 Update `docs/FEATURES.md` status for the export and GDPR rows
- [ ] 11.2 Add `docs/gdpr.md`: what the export package contains (and the E2E limitation when locked), exact deletion semantics for shared/delegated secrets (transfer vs detach-with-tombstone), and the event-emission scope pending the audit-trail change
