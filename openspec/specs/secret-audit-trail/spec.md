# secret-audit-trail Specification

## Purpose
TBD - created by archiving change add-secret-audit-trail. Update Purpose after archive.
## Requirements
### Requirement: Server-Observable Operation Capture
The system MUST record an audit entry for every server-observable secret operation: secret created, updated, read (individual encrypted-blob fetch), and deleted; folder cascade deletion; share granted, revoked, delegated, and delegation reclaimed; link share created, accessed, access-attempt failed, revoked, and auto-deleted; secret request created, fulfilled, re-requested, and revoked; suite revoked, reinstated, and compromise recovery started/completed; application registered, approved, rejected, deleted, token issued, and application secret retrieved; **emergency access granted, requested, declined, approved, accessed, revoked, and invalidated**.

Each entry MUST record the timestamp, actor (user, application, system, or anonymous link visitor), event type, object reference, and the object's non-sensitive name (denormalized so the entry survives object deletion). All entries MUST be written through typed events dispatched via `OCP\EventDispatcher` and a single audit listener; an audit-write failure MUST NOT fail or roll back the audited operation.

For emergency-access events the actor is the grantor (for `granted`, `declined`, `revoked`) or the grantee (for `requested`, `accessed`), with the system as actor for a timeout-driven `approved` and for an `invalidated` triggered by a key change; the object reference identifies the emergency-contact relationship (grantor and grantee), and no key material or secret value is ever recorded (the recovery envelope and private key MUST NEVER appear in an entry).

List and search calls are not per-secret reads and MUST NOT produce `secret.read` entries.

#### Scenario: Secret update is logged
@e2e tests/e2e/workflows/audit-trail.spec.ts
- **WHEN** a user updates a secret they own
- **THEN** an audit entry MUST be recorded with event type `secret.updated`, the user as actor, and the secret's id and name

#### Scenario: Emergency-access break-glass is logged end to end
@e2e exclude Server-side dispatch contract across the emergency-access lifecycle — asserting the recorded event types, actors, and the absence of key material is a DB/payload assertion, not a DOM flow; covered by PHPUnit (EmergencyAccessService dispatch + AuditService whitelist tests).
- **WHEN** grantee B requests emergency access to grantor A's vault, the wait period elapses to approval, and B then accesses the vault
- **THEN** audit entries MUST be recorded for `emergency_access.requested` (actor B), `emergency_access.approved` (actor system), and `emergency_access.accessed` (actor B)
- **AND** none of the entries MUST contain the recovery envelope, the grantor's private key, or any secret value

#### Scenario: Failed link-share access attempt is logged
@e2e exclude Server-side dispatch contract — asserting the recorded actor type, missing actor id, and absence of the attempted password is a DB/payload assertion, not a DOM flow; covered by PHPUnit (LinkShareService dispatch + AuditService whitelist tests).
- **WHEN** an anonymous visitor enters a wrong password on a link share
- **THEN** an audit entry MUST be recorded with event type `link_share.access_failed`, actor type `link_visitor`, and no actor id
- **AND** the entry MUST NOT contain the attempted password

#### Scenario: Entry survives object deletion
@e2e exclude Server-side denormalization contract — verifying the entry remains readable with the recorded name after deletion is a persistence assertion; covered by PHPUnit (AuditService record + AuditEntryMapper tests).
- **WHEN** a secret is deleted
- **THEN** the `secret.deleted` entry and all prior entries for that secret MUST remain readable, displaying the secret's recorded name

#### Scenario: Audit failure does not block the operation
@e2e exclude Fail-soft contract — simulating a listener failure and asserting the operation still succeeds + an error is logged is not DOM-observable; covered by PHPUnit (AuditListener fail-soft test).
- **WHEN** the audit listener fails while a secret is being created
- **THEN** the secret creation MUST succeed
- **AND** the failure MUST be logged at error level

### Requirement: Export and Deletion Event Consumption
The system MUST register listeners for the three typed events scoped by the secret-export capability — `SecretExportedEvent`, `GdprExportPerformedEvent`, and `AccountDataDeletedEvent` — and persist them as audit entries (`vault.exported`, `vault.gdpr_exported`, `vault.account_deleted`) carrying the events' existing payloads (mode, scope, counts, trigger) unchanged. No new dispatch sites are added for these operations.

Because export runs client-side, these entries cover the supported, client-reported flows only; this honest-client limitation is inherited from the secret-export capability and MUST be stated in the audit documentation rather than implied away.

#### Scenario: Export event becomes an audit entry
@e2e exclude Event-consumption contract — the secret-export-gdpr events are dispatched server-side and consumed by the listener; asserting the mapped audit row is a payload assertion; covered by PHPUnit (AuditListener export-event mapping test). The producing events are not yet built (secret-export-gdpr unimplemented), so the binding is class-existence-guarded.
- **WHEN** a `SecretExportedEvent` is dispatched for a user who exported 120 secrets as an encrypted backup
- **THEN** an audit entry MUST be recorded with event type `vault.exported` and metadata `mode: encrypted-backup`, `secretCount: 120`

#### Scenario: Account deletion becomes an audit entry
@e2e exclude Event-consumption contract — dispatched server-side and consumed by the listener; covered by PHPUnit (AuditListener export-event mapping + anonymization tests). Producing event not yet built; binding is class-existence-guarded.
- **WHEN** an `AccountDataDeletedEvent` is dispatched with trigger `user-deleted`
- **THEN** an audit entry MUST be recorded with event type `vault.account_deleted` carrying the per-entity deletion counts

### Requirement: No Secret Material in Audit Entries
Audit entries MUST NEVER contain secret values, login fields, additional fields, plaintext passwords, or ciphertext. Entry metadata MUST be validated against a per-event-type whitelist of keys; unknown keys MUST be dropped, and any attempt to record a forbidden key (`key`, `login`, `password`, `value`, `additionalFields`, `ciphertext`, `payload`) MUST be rejected with an error. A secret's `name` and `url` (plaintext metadata per the secrets spec) are the only object-identifying strings permitted.

#### Scenario: Forbidden metadata rejected
@e2e exclude Structural guarantee enforced in AuditService::record — a server-side validation/exception path with no DOM surface; covered by PHPUnit (AuditService forbidden-key tests, one per forbidden key + nested).
- **WHEN** a caller attempts to record an audit entry whose metadata contains a `password` key
- **THEN** the audit service MUST reject the entry with an exception

#### Scenario: Database dump reveals no secret material
@e2e exclude Direct-database assertion over recorded rows — not a DOM flow; covered by PHPUnit (AuditService whitelist tests assert only whitelisted keys persist and forbidden keys are rejected).
- **WHEN** the full `keepiq_audit_log` table is inspected directly
- **THEN** no row contains a secret value, login field, additional field, or ciphertext in any column

### Requirement: Append-Only Log
The audit log MUST be append-only at the application surface: no API endpoint and no service method may edit or delete individual entries. The only permitted mutations are the retention purge and account-deletion anonymization, both internal to the audit service.

#### Scenario: No mutation surface exists
@e2e exclude Architectural invariant — enumerating routes + the AuditService public API for the absence of an edit/delete-entry verb is a code-surface assertion (the controller exposes GET only); covered by route-auth/route-reachability gates + AuditController tests, not a DOM flow.
- **WHEN** the registered routes and audit service public API are enumerated
- **THEN** no operation exists that updates or deletes an individual audit entry

### Requirement: Retention and Automated Purge
The retention window MUST be configurable by an administrator (`audit_retention_days`, default 365), with a hard minimum of 30 days that cannot be configured lower. A nightly background job MUST delete entries older than the window in bounded batches.

#### Scenario: Expired entries purged
@e2e exclude Background-job behaviour — requires aging rows past the window and running the TimedJob; not DOM-testable; covered by PHPUnit (AuditService purge batch test).
- **WHEN** the purge job runs with a 365-day retention window
- **THEN** all entries older than 365 days MUST be deleted
- **AND** entries within the window MUST be untouched

#### Scenario: Retention floor enforced
@e2e exclude Server-side validation of the 30-day floor — the SettingsService rejects below-minimum values with a 400; covered by PHPUnit (SettingsService retention-floor test). The admin UI surfaces the error but the authoritative check is server-side.
- **WHEN** an administrator attempts to set retention to 7 days
- **THEN** the system MUST reject the value with an error explaining the 30-day minimum

### Requirement: Account-Deletion Anonymization
When a user's account data is deleted (`AccountDataDeletedEvent`), the system MUST anonymize the deleted user out of existing audit entries: entries with the user as actor have the actor id replaced by a `deleted-account` marker, and whitelisted metadata fields referencing the user are replaced by the same marker. The entries themselves MUST be retained — other users' accountability records reference these operations. No user id, display name, or other personal data of the deleted user may remain in the log afterwards.

#### Scenario: Actor anonymized on account deletion
@e2e exclude Server-side anonymization contract — asserting actor-id + metadata scrub across the table is a persistence assertion; covered by PHPUnit (AuditService anonymizeUser test asserting no occurrence of the deleted user id remains).
- **WHEN** a user's account data is deleted
- **THEN** all audit entries previously listing that user as actor MUST show the `deleted-account` marker instead of the user id
- **AND** entries recording shares to that user MUST no longer contain the user id in metadata

#### Scenario: Other users' history preserved
@e2e exclude Server-side retention-vs-deletion contract — verifying the colleague's entry survives anonymization is a persistence assertion; covered by PHPUnit (AuditService anonymizeUser test: entries retained, only the departed user scrubbed).
- **WHEN** a user who had shared a secret to a colleague is deleted
- **THEN** the colleague's `share.granted` entry MUST still exist (anonymized), preserving when and how they received access

### Requirement: Per-Secret and Personal Activity Views
The system MUST provide a per-secret activity view (entries for one secret, newest first) available to the secret's current owner, and a personal activity view listing the session user's own operations. A share recipient MUST see only entries they actored on the shared secret, not the owner's full history. Requesting the activity of a secret the user has no access to MUST return the same response as a nonexistent secret (no existence oracle).

#### Scenario: Owner views secret activity
@e2e tests/e2e/workflows/audit-trail.spec.ts
- **WHEN** an owner opens the Activity tab of their secret
- **THEN** the entries for that secret MUST be listed newest first with actor, event type, and timestamp

#### Scenario: Non-owner blocked without existence oracle
@e2e exclude No-existence-oracle is an HTTP-response-equality assertion (non-owned vs nonexistent return identical 404s) — an API contract, not a DOM flow; covered by PHPUnit (AuditController tests assert identical 404 for both).
- **WHEN** a user requests the activity of a secret they do not own
- **THEN** the response MUST be indistinguishable from requesting a nonexistent secret

### Requirement: Admin Audit View
The system MUST provide administrators an instance-wide audit view: paginated (50 per page), filterable by event type, actor, object type/id, and date range, and exportable as CSV generated client-side from the fetched rows. Non-administrators MUST NOT be able to reach the admin audit endpoints. The view MUST state that the trail starts at the deployment of this capability (no historical backfill).

#### Scenario: Admin filters by event type and actor
@e2e tests/e2e/workflows/audit-trail.spec.ts
- **WHEN** an administrator filters the audit view to `link_share.access_failed` within a date range
- **THEN** only matching entries MUST be returned, paginated with a total count

#### Scenario: Non-admin rejected
@e2e exclude Authorization-attribute enforcement — the endpoint carries #[AuthorizedAdminSetting] so NC middleware rejects non-admins before the controller runs; covered by route-auth/semantic-auth gates + AuditController admin-scope reasoning, not a DOM flow.
- **WHEN** a regular user calls the instance-wide audit endpoint
- **THEN** the request MUST be rejected by the admin authorization check

