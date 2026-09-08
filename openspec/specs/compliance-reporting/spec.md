# Compliance Reporting Specification

**Status**: done

**OpenSpec changes:** [compliance-reporting](../../changes/compliance-reporting/)

## Purpose

Dutch public-sector buyers must evidence credential-hygiene conformity to auditors, and no self-hosted OSS vault ships the artifact they show. BIO2 v1.3 (Jan 2026) names "een wachtwoordmanager aanbieden" as a measure, and NIS2 / Cyberbeveiligingswet (in force ~Aug 2026 for ~8,000 Dutch organisations including all municipalities) requires demonstrable cyber-hygiene and access-management measures. This feature gives Keepiq admins/CISOs an org-level compliance evidence report over server-visible metadata — adoption, secrets-per-user counts, share hygiene, rotation posture, audit-trail integrity, and emergency-access coverage — persisted as an immutable timestamped snapshot with a config snapshot and exportable as CSV/PDF, while honestly stating the zero-knowledge boundary that no password strength or breach statistic exists server-side.

## Requirements

### Requirement: Org-level metadata-only compliance report
The system MUST provide administrators an org-level compliance report aggregating only server-visible metadata (adoption, secrets-per-user counts, share hygiene, rotation posture, audit-trail integrity, emergency-access coverage), containing counts and aggregates only — never a secret value, name, login field, or ciphertext — and computed without any decryption.

#### Scenario: Report aggregates the metadata sections
- GIVEN an administrator with a populated instance
- WHEN they generate a compliance report
- THEN the system MUST include the adoption, secrets-per-user, share-hygiene, rotation-posture, audit-integrity, and emergency-access-coverage sections with counts only

### Requirement: Zero-knowledge honesty boundary
The system MUST NOT include any password strength, reuse, or breach statistic and MUST NOT include any per-secret verdict; ciphertext-age figures derived from `key_updated_at` MUST be labelled as ciphertext-age, and the report MUST print its metadata-only boundary statement.

#### Scenario: No strength or breach statistics appear
- GIVEN a vault whose passwords a client has locally scored and breach-checked
- WHEN a compliance report is generated
- THEN the report MUST NOT contain any strength score, reuse figure, or breach count, and MUST print the metadata-only boundary statement

### Requirement: Immutable timestamped evidence snapshot
The system MUST persist each generated report as an immutable snapshot carrying a generation timestamp, generating administrator, app-version stamp, and a config snapshot of the settings the aggregate was computed under; no operation MUST edit a persisted snapshot.

#### Scenario: Snapshot records provenance
- GIVEN an administrator generates a report
- WHEN the snapshot is persisted
- THEN it MUST record the generation timestamp, generating admin, app version, and config snapshot, and MUST NOT be editable afterward

### Requirement: CSV and PDF export
The system MUST let administrators export a persisted snapshot as CSV and PDF, each carrying the snapshot's generation timestamp and config snapshot and reporting identical figures.

#### Scenario: Export carries provenance
- GIVEN a persisted snapshot
- WHEN an administrator exports it as CSV or PDF
- THEN each export MUST carry the generation timestamp and config snapshot and MUST report identical figures

### Requirement: Admin-only access
The system MUST restrict all compliance-report endpoints to administrators.

#### Scenario: Non-admin rejected
- GIVEN a regular authenticated user
- WHEN they call any compliance-report endpoint
- THEN the request MUST be rejected by the admin authorization check before report logic runs

## User Stories

- As a municipal CISO, I want an org-level BIO2/NIS2 compliance report so that I can evidence credential-hygiene conformity to auditors
- As an administrator, I want each report persisted with a timestamp and config snapshot so that the evidence is reproducible and defensible
- As an auditor, I want the report to honestly state what the server can and cannot know so that I can trust its figures

## Acceptance Criteria

- [ ] Report aggregates the six metadata sections with counts only, no secret material
- [ ] No strength/reuse/breach statistic appears; metadata-only boundary printed
- [ ] Ciphertext-age figures labelled as ciphertext-age, not strength
- [ ] Each report persisted as an immutable snapshot with timestamp + config snapshot
- [ ] CSV and PDF export carry provenance and identical figures
- [ ] All endpoints reject non-admins before report logic runs
- [ ] Generation/export emit audit events carrying only identifiers/format

## Notes

- Own tables per ADR-001 (`keepiq_compliance_reports`); no OpenRegister.
- Depends on `rotation-expiry-policies` (wave-1) for the rotation-posture section, class-existence-guarded.
- Honesty boundary enforces password-health's "No Server-Side Health Knowledge" invariant (`openspec/specs/password-health/spec.md`).
- Related specs: secret-audit-trail (integrity section, append-only CSV pattern), emergency-access (coverage section), admin-settings (panel conventions).
