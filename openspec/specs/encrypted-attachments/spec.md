# Encrypted Attachments Specification

**Status**: done

**OpenSpec changes:**
- `encrypted-attachments` (2026-07-16) — Client-side encrypted file attachments on secrets: AES-256-GCM blob encrypted once under a random file key, RSA-wrapped per recipient (envelope model, no blob duplication); ciphertext blobs at rest in Nextcloud app data, metadata + wrapped keys in own DB tables; admin-configurable per-attachment size limit and per-user quota; inclusion in sharing, GDPR export, and suite-independent encrypted backup; deletion cascade and typed audit events

## Purpose

Secrets frequently arrive with an accompanying file — license keys, TLS certificates and key material, 2FA recovery-code sheets — that belongs with the credential. Encrypted attachments let a user store those files against a secret under Keepiq's zero-knowledge model: the bytes and filename are encrypted in the browser before upload, the server stores only ciphertext it cannot read, and an attachment follows a shared secret to its recipients by re-wrapping a single file key per recipient rather than duplicating the blob. This closes a durable competitive gap — Vaultwarden and Psono ship attachments while Nextcloud Passwords has lacked them since 2019 — without introducing any new cryptographic primitive beyond the existing RSA/AES suite (ADR-003).

## Requirements

### Requirement: Client-side encrypted attachment upload
The system MUST allow the owner of a secret to attach a file whose bytes, filename, and content-type are encrypted in the browser before upload, storing only ciphertext and non-content metadata server-side.

#### Scenario: Upload stores ciphertext only
- GIVEN an owner with an unlocked vault viewing a secret they own
- WHEN they upload a file as an attachment
- THEN the browser MUST encrypt the bytes and metadata under a random AES-256-GCM file key, RSA-wrap that key under the owner's public certificate, and POST only ciphertext
- AND no HTTP request MUST contain the plaintext bytes, plaintext filename, or unwrapped file key

### Requirement: Single-blob envelope with per-recipient key wrapping
The system MUST encrypt an attachment's bytes exactly once under a random file key and store one shared ciphertext blob, representing each party's access as a per-copy grant carrying the file key wrapped under that copy's public certificate. The blob MUST NOT be duplicated per recipient.

#### Scenario: Sharing re-wraps the key, not the blob
- GIVEN an owner shares a secret with an attachment to a recipient with an active EncryptionSuite
- WHEN the share is created
- THEN the browser MUST re-wrap the file key under the recipient's certificate and create a grant referencing the existing blob
- AND the blob MUST NOT be re-uploaded or duplicated

### Requirement: Per-attachment size limit and per-user quota
The system MUST enforce an admin-configurable per-attachment maximum size (default 25 MiB) and per-user storage quota (default 100 MiB) in stored ciphertext bytes, server-side at upload time.

#### Scenario: Quota exhaustion rejected
- GIVEN a user at or near their attachment quota
- WHEN an upload would exceed the quota
- THEN the server MUST reject it and persist nothing
- AND deleting an attachment MUST reclaim the freed bytes

### Requirement: Attachments included in export and backup
The system MUST include attachments in the GDPR personal-data export and in the suite-independent encrypted backup, with metadata and wrapped keys server-side and decrypted content assembled client-side.

#### Scenario: Encrypted backup restores attachments
- GIVEN a backup produced from a vault containing attachments
- WHEN it is restored with the correct passphrase
- THEN each secret's attachments MUST be reproduced, decryptable from the passphrase alone

### Requirement: Attachment deletion cascade
The system MUST delete a secret's attachments (grants and reference-counted blobs) when the secret is deleted, when a containing folder is cascade-deleted, and when a user's account data is deleted; revoking a recipient's share or suite MUST remove only that recipient's grants.

#### Scenario: Deleting a secret removes its attachments
- GIVEN a secret with attachments
- WHEN the owner deletes it
- THEN all grants MUST be deleted, every blob whose last grant was removed MUST be unlinked, and quota MUST be reclaimed

### Requirement: Attachment operations are auditable
The system MUST dispatch typed events for attachment upload, download, and deletion carrying only non-sensitive identifiers — never the plaintext filename, file key, or content.

#### Scenario: Upload dispatches a content-free event
- GIVEN an attachment is uploaded
- WHEN the upload completes
- THEN an `AttachmentUploadedEvent` MUST be dispatched carrying only attachment id, secret id, ciphertext size, and timestamp

## User Stories

- As a user, I want to attach a license file to its secret so that the key and its file stay together
- As a user, I want my attachments encrypted client-side so that the server can never read them
- As a user, I want an attachment to follow a secret I share so that my colleague gets the file too
- As a user, I want my attachments in my GDPR export and encrypted backup so that nothing is left behind or lost
- As an admin, I want to cap attachment size and per-user storage so that the instance stays within budget

## Acceptance Criteria

- [ ] Attachment bytes, filename, and content-type are encrypted client-side; server stores only ciphertext + non-content metadata
- [ ] One physical blob per file, deduplicated across owner and recipients; only the wrapped file key is per-recipient
- [ ] Sharing re-wraps the file key without re-uploading the blob; the blob is removed only when its last grant is deleted
- [ ] Per-attachment size limit and per-user quota are admin-configurable and enforced server-side
- [ ] Attachments appear in GDPR export and the suite-independent encrypted backup, and restore from it
- [ ] Deleting a secret, cascading a folder, or deleting an account removes attachments and reclaims quota
- [ ] Revoking a recipient's share/suite removes only their grants; the owner's attachment survives
- [ ] Upload/download/delete dispatch typed audit events carrying no filename, key, or content

## Notes

- Storage backend (provisional): ciphertext blobs in Nextcloud app data (`IAppData`); metadata + wrapped keys in own DB tables (`keepiq_attachments`, `keepiq_attachment_grants`). See the change's design "Decisions made under uncertainty".
- Out of scope for v1: server-side preview/thumbnailing/scanning (impossible under ADR-003), and attachments on link shares / secret requests (deferred).
- Related ADRs: ADR-001 (own DB tables), ADR-003 (RSA/AES encryption architecture).
