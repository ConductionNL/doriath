## Why

`docs/FEATURES.md` makes three **V1** compliance claims that are currently neither specced nor built: "Secret export (encrypted backup, CSV)" (Data portability), "GDPR data export (all user secrets + metadata)" (Right of access), and "GDPR data deletion (user + all shares)" (Right to erasure). For an app marketed to Dutch municipalities and government bodies, GDPR Articles 15 and 17 support is not optional polish — it is a compliance claim the feature matrix already makes at V1.

Export is also the user's only insurance against the app's own biggest documented risk: FEATURES.md rates "Master password lost = data lost" **High**. An encrypted backup the user controls is the pragmatic v1 mitigation while emergency access remains undecided.

Deletion is the hard part: Keepiq secrets can be shared (user-sharing spec: recipient copies are full Secret rows encrypted under the *recipient's* suite, kept in sync), delegated, link-shared, and requested. "Delete the user" without defined semantics either orphans recipients' working copies silently or destroys data that other users legitimately hold. This change defines those semantics explicitly: ownership transfer through existing permanent delegation where it exists, and detach-with-tombstone for recipient copies otherwise.

Finally, FEATURES.md promises "Audit trail on all secret operations" at V1, but no audit trail exists yet. This change scopes its accountability obligation to **emitting typed events** for export and deletion operations, so the future `add-secret-audit-trail` change has consumable signals from day one instead of retrofitting them.

## What Changes

- Implement **encrypted backup export**: the browser decrypts the user's vault (vault must be unlocked), serializes secrets + folder structure to a versioned JSON document, and encrypts it with AES-256-GCM under a key derived from a user-chosen backup passphrase via Argon2id (reusing the WASM KDF module and parameters from implement-link-sharing). The `.doriath-backup` file is generated entirely client-side
- Implement **plaintext CSV export**: gated behind explicit master-password re-entry (even with an unlocked session) and an explicit warning dialog; generated client-side in a format the `secret-import` generic CSV parser round-trips; never transmitted to the server
- Implement export scope selection (entire vault or selected folders) for both export modes
- Implement **GDPR personal data export** (Art. 15): a machine-readable JSON package combining the server-side metadata export (suites without private keys, shares given/received, link-share metadata, secret requests, delegations, settings) with the client-side decrypted vault, assembled in the browser
- Implement **account data deletion** (Art. 17): an in-app flow (master-password re-entry + typed confirmation) and an automatic cascade on Nextcloud user deletion (`UserDeletedEvent` listener) that removes all secrets, folders, suites, shares, link shares, secret requests, and settings
- Define **shared-secret semantics on deletion**: secrets with an active permanent delegation transfer ownership to the delegate; recipient share-copies are detached into independent secrets (already encrypted under the recipient's suite) marked with a non-personal tombstone (`shared by a deleted account` provenance, no user ID retained); all sync links, link shares, and pending requests are destroyed
- Implement **typed audit events** dispatched via `OCP\EventDispatcher` for export and deletion operations (`SecretExportedEvent`, `GdprExportPerformedEvent`, `AccountDataDeletedEvent`) carrying counts and modes but never secret content — scoped to event emission only, for the future audit-trail change to consume

## Capabilities

### New Capabilities
- `secret-export`: User-initiated vault export — encrypted `.doriath-backup` (Argon2id + AES-256-GCM, client-side), plaintext CSV behind re-auth + warning, scope selection, versioned round-trippable backup format, and server-side export event emission
- `gdpr-compliance`: GDPR data-subject rights — full personal data export package (Art. 15), account data deletion with defined shared-secret semantics (ownership transfer via permanent delegation, detach-with-tombstone for recipient copies) (Art. 17), automatic cascade on Nextcloud user deletion, and typed deletion/export audit events

### Modified Capabilities
_(none in delta form — the user-sharing spec's existing SecretDelegation `is_permanent` semantics ("owner's suite was revoked/deleted") are consumed as-is; the deletion cascade composes existing per-entity delete operations)_

## Impact

- **Database**: One small migration — `tombstoned_at` (datetime, nullable) and `tombstone_reason` (string, nullable) columns on `keepiq_secrets` for detached recipient copies. No new tables
- **Backend**: New `ExportController` (GDPR metadata package endpoint + export-event endpoints), new `AccountDeletionService` (cascade orchestration + shared-secret semantics), `UserDeletedEvent` listener registered in `Application.php`, three typed event classes under `lib/Event/`
- **Frontend**: New `src/export/` serializer modules (backup format, CSV, GDPR package assembly), new Pinia store (`useExportStore`), export dialog and account-deletion dialog (own files per ADR-004), user-settings entry points
- **API**: `GET /api/v1/gdpr/metadata` (server-side personal-data metadata), `POST /api/v1/export/events` (export event emission), `DELETE /api/v1/gdpr/account-data` (in-app deletion)
- **Dependencies**: Depends on `implement-encryption-suites` (archived), `implement-secrets`, `implement-secrets-write-ui`, `implement-user-sharing` (SecretShare/SecretDelegation semantics), `implement-link-sharing` (Argon2id WASM module, cascade methods), `implement-secret-requests` (request cascade). The backup format is consumed by `secret-import` (round-trip)
- **Security**: Plaintext export requires fresh master-password proof-of-knowledge (client-side decrypt test — the password itself still never leaves the browser, per the encryption-suites spec); backup passphrase has a zxcvbn ≥ 3 strength floor; deletion is irreversible and double-confirmed; audit events carry no secret material; tombstones carry no personal data of the deleted user
- **Cross-app**: Registers with Nextcloud's user lifecycle (`UserDeletedEvent`). No OpenRegister involvement (Keepiq owns its tables)
