# Application Management Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-application-mgmt` (2026-04-01) — Full implementation: registration, CSR/generated key pair, approval queue, JWT auth, write-without-read, cascade deletion
- `admin-application-request-visibility` (2026-08-18) — An application can ask humans for credentials that no person can see: `created_by` is `application:<id>` and the user listing matches the acting user's id, the target Secrets are application-owned so they appear in no vault, and the only lister is the application's own Bearer endpoint (6 such requests measured on development). Adds an admin-scoped listing and revoke on the application's detail page, leaving the user-side listing untouched

## Purpose

@e2e exclude No application-management UI is built in v0.1; all scenarios exercise registration, CSR/key-pair handling, and JWT-auth API flows — covered by integration tests, not Playwright UI flows.

External and internal applications can be registered in Keepiq so that secrets can be attributed to them. An application gets its own EncryptionSuite, allowing secrets to be encrypted specifically for that application. The application can then retrieve and decrypt its own secrets via the API.

Registration is open (anyone can register, even anonymously), but non-admin registrations go into a pending queue for approval by the vault administrator. Secrets cannot be attributed to a pending application.

## Data Model

### Application

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `name` | string | No | Human-readable name |
| `type` | enum | No | `internal` (Nextcloud app) or `external` |
| `status` | enum | No | `pending`, `active` |
| `registered_by` | string | No | Nextcloud user ID or null (anonymous) |
| `approved_by` | string | No | Admin user ID; null if pending |
| `created_at` | datetime | No | |
| `approved_at` | datetime | No | |

The Application's encryption identity is provided via its EncryptionSuite(s), linked via the polymorphic `owner_type = application` pattern (see ADR-002).

## Requirements

### Requirement: Register Application
The system MUST allow any user (including anonymous) to register an application.

#### Scenario: Admin registers application
- GIVEN the registrant is a Nextcloud administrator or vault app administrator
- WHEN they submit a registration
- THEN the application MUST be created with status `active` immediately

#### Scenario: Non-admin registers application
- GIVEN the registrant is a regular user or anonymous
- WHEN they submit a registration
- THEN the application MUST be created with status `pending`
- AND no secrets can be attributed to it until it is approved

### Requirement: Approval Queue
The system MUST allow vault administrators to view and approve or reject pending applications.

#### Scenario: Approve application
- GIVEN an application is pending
- WHEN a vault administrator approves it
- THEN the application status MUST be set to `active`

#### Scenario: Reject application
- GIVEN an application is pending
- WHEN a vault administrator rejects it
- THEN the application MUST be removed from the queue and deleted

### Requirement: EncryptionSuite via CSR
When a CSR is uploaded during registration, the system MUST use the public key from the CSR to create an EncryptionSuite for the application.

#### Scenario: Register with CSR
- GIVEN a registrant uploads a valid CSR
- WHEN the application is approved (or auto-approved for admin)
- THEN the system MUST extract the public key from the CSR, sign it with the CA intermediate, and create an EncryptionSuite for the application
- AND the private key is NOT stored — the application manages it externally

#### Scenario: Register without CSR
- GIVEN no CSR is uploaded
- WHEN the application is approved
- THEN the system MUST generate a 4096-bit RSA key pair, sign the certificate, and create an EncryptionSuite
- AND the private key MUST be returned to the registrant once (never stored in plaintext)

### Requirement: Delete Application
A vault administrator MUST be able to delete an active application. Deletion is permanent — there is no deactivation or soft-delete state.

On deletion:
- The application record is removed
- The application's EncryptionSuite is removed
- All secrets attributed to the application are hard-deleted

#### Scenario: Delete active application
- GIVEN an application is active
- WHEN a vault administrator deletes it
- THEN the application, its EncryptionSuite, and all its secrets MUST be permanently deleted
- AND this action MUST NOT be reversible

### Requirement: Admin Notification on Pending Registration
When a non-admin submits an application registration, all vault administrators MUST be notified via the Nextcloud built-in notification system (bell icon).

Notification content:
- Title: "New application pending approval"
- Body: "Application *{name}* is awaiting approval."
- Action link: opens the approval queue in Keepiq

#### Scenario: Non-admin registers application
- GIVEN a non-admin submits a registration
- WHEN the application is created with status `pending`
- THEN a Nextcloud notification MUST be dispatched to all vault administrators

### Requirement: Pending Applications Counter on Dashboard
The Keepiq dashboard MUST display a visible counter of pending application registrations to vault administrators. Non-administrators MUST NOT see this counter.

#### Scenario: Pending applications exist
- GIVEN one or more applications are in `pending` status
- WHEN a vault administrator views the Keepiq dashboard
- THEN the dashboard MUST show the count of pending registrations
- AND the counter MUST link to the approval queue

#### Scenario: No pending applications
- GIVEN no applications are pending
- WHEN a vault administrator views the Keepiq dashboard
- THEN the counter MUST NOT be shown (or shown as zero, implementation choice)

### Requirement: Attribute Secrets to Application
Once an application is active, users with appropriate permission MUST be able to write secrets into the application's vault (encrypted with the application's public certificate).

#### Scenario: Write secret for application
- GIVEN an application is active and has an EncryptionSuite
- WHEN a user with permission writes a secret for the application
- THEN the secret MUST be encrypted with the application's public certificate
- AND the writing user MUST NOT be able to read it back (write-without-read)

### Requirement: Outstanding Application Requests Visible to Administrators

An administrator MUST be able to see the secret requests an application has created, and MUST be able to revoke them.

Today they cannot. `created_by` for an application request is `application:<id>`, and the user-facing listing matches `created_by` against the acting user's id, so no person can enumerate them; the target Secrets are application-owned and appear in no user's vault; and the only lister is the application's own Bearer-authenticated endpoint. Creation is recorded in the audit trail, so the events exist with nowhere to read them as state.

This matters because a pending fill link is a bearer credential in a URL. An administrator accountable for an approved application MUST be able to answer what credentials it is currently asking humans for, and MUST be able to end a circulating link without the application's cooperation.

Visibility MUST be scoped to administrators, not to whoever registered the application: an application's vault belongs to no single user, and registration is a historical act rather than continuing responsibility.

The listing MUST NOT render a request's full token, and MUST NOT expose any submitted value — write-without-read is unaffected by who is looking.

#### Scenario: An administrator sees what an application is asking for
@e2e exclude No Playwright coverage of the admin application page yet; driven by ApplicationRequestAdminControllerTest::testAnAdministratorSeesTheApplicationsRequests, SecretRequestServiceTest::testListForApplicationReturnsThatApplicationsRequestsNewestFirst and the SecretRequestList vitest "renders the application rows". Verified live on 2026-08-19: the endpoint returned each application's own two requests with their status and requested field names.
- **GIVEN** an application has created pending secret requests
- **WHEN** an administrator opens that application
- **THEN** those requests MUST be listed with their status, requested field names and expiry

#### Scenario: A non-administrator cannot list them
@e2e exclude Authorization, asserted at both layers rather than through a browser: ApplicationRequestAdminControllerTest::testANonAdministratorIsRefused (and ::testAnAnonymousCallerIsRefused) plus SecretRequestServiceTest::testListForApplicationRefusesANonAdmin, which fails when the service guard is removed. Registrar identity is not an input to either check. Verified live: alice received 403.
- **WHEN** a user who is not an administrator requests an application's secret requests
- **THEN** the system MUST refuse, regardless of whether that user registered the application

#### Scenario: The user's own listing is unchanged
@e2e exclude Regression guard on a query, not a UI flow; driven by SecretRequestServiceTest::testListByUserStillQueriesTheRawUid, which pins that the uid is passed with no application prefix — the reason a user listing can never match an application's rows.
- **GIVEN** an instance with both user-created and application-created requests
- **WHEN** a user lists their own requests
- **THEN** only requests they created MUST be returned, exactly as before

#### Scenario: An administrator revokes a circulating fill link
@e2e exclude Driven by ApplicationRequestAdminServiceTest::testAdminRevokeDeletesTheUnfilledApplicationPlaceholder, ::testAdminRevokeNeverDeletesAFilledApplicationSecret, ::testAdminRevokeWillNotDeleteAnotherApplicationsSecret and ::testRevokeForApplicationRefusesARequestOfAnotherActor (which fails when the created_by check is removed), plus the vitest "asks before revoking, and revokes through the application endpoint". NOT verified live: doing so would hard-delete a seeded placeholder Secret on the development instance, and the request row cannot be restored.
- **GIVEN** an application has a pending request whose link is in circulation
- **WHEN** an administrator revokes it
- **THEN** the token MUST stop being fillable
- **AND** the unfilled placeholder Secret MUST be deleted, per the Revoke Request requirement

#### Scenario: The listing does not leak the token or the values
@e2e exclude Driven by the vitest "never renders a full token", which asserts the truncated form is shown and neither full token appears anywhere in the rendered output. The API deliberately still returns the token — the copy-link action needs it — so truncation is the view's job, exactly as on the user side. No submitted value is readable from a listing because the response carries only metadata (write-without-read, ADR-003).
- **WHEN** an application's requests are listed for an administrator
- **THEN** each row MUST show the token truncated
- **AND** no submitted value MUST be readable from the listing

## User Stories

- As a developer, I want to register my application so that I can store and retrieve its secrets from Keepiq
- As an administrator, I want to approve or reject application registrations so that I control which applications can use the vault
- As a user, I want to write a secret for an application without being able to read it so that sensitive values are never in my hands
- As an application, I want to retrieve my secrets via the API using my private key so that I can configure myself securely

## Acceptance Criteria

- [ ] Any user (including anonymous) can submit an application registration
- [ ] Admin-registered applications are immediately active
- [ ] Non-admin registrations are placed in a pending queue
- [ ] Vault administrators can approve or reject pending applications
- [ ] An approved application gets an EncryptionSuite (via CSR or generated)
- [ ] If a CSR is uploaded, the private key is not stored — only the signed certificate
- [ ] If no CSR is uploaded, a key pair is generated and the private key returned once
- [ ] Secrets cannot be attributed to pending applications
- [ ] Writing a secret for an application encrypts it with the app's public certificate
- [ ] All vault administrators receive a Nextcloud notification when a new application registration is pending
- [ ] The Keepiq dashboard shows a pending application counter to vault administrators (hidden from non-admins)
- [ ] Vault administrators can delete an active application
- [ ] Deletion permanently removes the application, its EncryptionSuite, and all its secrets (hard delete, no soft-delete or deactivation state)

## Design Decisions

### Application API Authentication — RFC 7523 (JWT Bearer / Private Key JWT)

**Decision:** RFC 7523 — OAuth2 client credentials flow where the application authenticates by presenting a JWT signed with its own RSA private key.

**Why:** Standard pattern (used by Google service accounts), uses the existing key infrastructure (the application already has an RSA key pair from registration), produces short-lived access tokens, and introduces no new credential to manage. Keepiq owns the `/oauth2/token` endpoint since Nextcloud's oauth2 app does not support this grant type.

**Recovery:** If the private key is lost, re-registration is the recovery path (new key pair, new EncryptionSuite; existing secrets encrypted with the old public key become inaccessible).

**Alternatives considered:**
- Static API token (`X-Vault-Token`) — simple but the token is a credential that needs protecting separately
- Custom private-key challenge-response — pure but non-standard

**Status:** Chosen route. May be revisited if implementation reveals blockers with Nextcloud's request lifecycle or JWT library availability in PHP 8.3+.

## Notes

- "Internal vs external" application distinction does not currently affect functionality — both types go through the same registration flow. The type field is informational for now.
- The master password challenge for internal applications (how does a Nextcloud app running a cronjob authenticate?) is a known open problem documented in the Vault-app.docx. It is not scoped in this spec.
- Related ADRs: ADR-002 (polymorphic ownership), ADR-003 (encryption architecture)
