## Why

Keepiq competes against established password managers — most prominently the incumbent Nextcloud Passwords app (500K+ downloads), Bitwarden, and KeePass. `docs/FEATURES.md` promises "Secret import (CSV, Bitwarden JSON, KeePass XML)" at the **V1** tier ("Migration from other tools") and its own risk table names Passwords-app incumbency as a top adoption risk ("Consider migration tooling"). Nothing is specced or built: a new user with an existing vault currently has to re-type every credential by hand, which in practice means they never switch.

Import is uniquely sensitive in Keepiq because of the always-E2E architecture (ADR-003, encryption-suites spec): the server must never see plaintext secret values. A naive "upload your Bitwarden export" endpoint would hand the server a plaintext file of every password the user owns — the exact thing the encryption model exists to prevent. Import therefore has to be designed as a client-side pipeline, not bolted on later.

## What Changes

- Implement a client-side import pipeline: the export file is read via the File API and parsed **entirely in the browser**; plaintext rows never leave the page. Each accepted row is encrypted with the owner's active EncryptionSuite public certificate (the same WebCrypto path as Create Secret) before anything is POSTed
- Implement format parsers for: generic CSV (user-adjustable column mapping), Bitwarden JSON export, Bitwarden CSV export, KeePass 2.x XML export, and the Nextcloud Passwords app JSON backup (dedicated migration path including folders and custom fields)
- Document KDBX (binary KeePass container) as explicitly out of scope for v1, with an actionable rejection message pointing users at KeePass's own "Export → KeePass XML" function
- Implement a field-mapping preview step: detected source fields mapped onto Keepiq's Secret fields (name, url, login, key, additional_fields, folder), adjustable for generic CSV, fixed-but-previewed for known formats; nothing is persisted until the user confirms
- Implement folder/collection mapping: source folders, Bitwarden collections/folders, KeePass groups, and Passwords-app folders map onto existing Keepiq folders or are created on commit, preserving hierarchy
- Implement client-side duplicate detection against the user's existing vault (plaintext name + url match) with per-row resolution (skip / import as copy) and a bulk-apply default
- Implement chunked batch commit: a new authenticated endpoint accepts arrays of already-encrypted secret payloads and creates them transactionally per chunk; the server validates only metadata and the ciphertext envelope
- Implement malformed-row rejection handling: a bad row never aborts the import; rejected rows are collected with row number and reason and offered as a client-side download
- Implement an import summary report (imported / skipped duplicates / rejected / folders created), displayed once and never stored server-side

## Capabilities

### New Capabilities
- `secret-import`: Client-side import of secrets from generic CSV, Bitwarden JSON/CSV, KeePass 2.x XML, and Nextcloud Passwords app backups — format detection, field-mapping preview, folder/collection mapping, duplicate detection, chunked encrypted commit, malformed-row rejection, and a transient import summary report. Plaintext never reaches the server (consistent with the encryption-suites E2E guarantees)

### Modified Capabilities
_(none — import composes the existing Create Secret and Create Folder operations; it does not change the secrets or encryption-suites requirements, it inherits them)_

## Impact

- **Database**: No new tables. Import produces ordinary `keepiq_secrets` and `keepiq_folders` rows
- **Backend**: New `ImportController` with a chunked batch-create endpoint (`POST /api/v1/secrets/import-batch`) delegating to the existing SecretService/FolderService; per-item validation errors are returned per index, not as a whole-request failure
- **Frontend**: New `src/import/` parser modules (csv, bitwarden, keepass-xml, nc-passwords) with a common normalized intermediate row model; new Pinia store (`useImportStore`); new import wizard dialog (file pick → mapping preview → duplicates → commit progress → summary); entry point in the vault toolbar/settings
- **API**: One new authenticated endpoint for batch commit; everything else reuses existing folder/secret APIs
- **Dependencies**: Depends on `implement-encryption-suites` (archived — CryptoKey session, WebCrypto encrypt path) and `implement-secrets` / `implement-secrets-write-ui` (Secret/Folder entities, services, stores, vault UI). New npm dependency: `papaparse` for robust CSV parsing (RFC 4180 quoting/escaping); KeePass XML and the two JSON formats are parsed with `DOMParser` / `JSON.parse` — no further dependencies
- **Security**: The import file is read only in browser memory; rows are encrypted client-side with the owner's public certificate before transmission; the batch endpoint accepts ciphertext + plaintext-safe metadata (name, url, folder) only — the same fields the normal create endpoint already accepts in plaintext per the secrets spec. The vault must be unlocked (CryptoKey in session) to start an import
- **Cross-app**: None. The Nextcloud Passwords migration is file-based (user exports a backup from Passwords, imports it here) — no direct DB or API coupling to the Passwords app
