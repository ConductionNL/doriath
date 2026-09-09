# secret-store-api Specification

## Purpose
TBD - created by archiving change openconnector-secret-store-api. Update Purpose after archive.
## Requirements
### Requirement: Machine API Discovery Document
The system MUST serve an unauthenticated, machine-readable discovery document declaring the API version, token endpoint, supported grant type (`urn:ietf:params:oauth:grant-type:jwt-bearer`), assertion requirements (algorithm, maximum lifetime, audience), the secret endpoint paths (list, by-id, by-name), and the supported envelope formats. The canonical location is `GET /api/v1/app/.well-known/keepiq`. The identical document MUST also be served at the pre-rename path `GET /api/v1/app/.well-known/doriath`, until that path is removed before the first stable release. The document MUST contain no instance-private data. Breaking changes to addressing or envelope shape MUST be published as a new API version in this document, never as an in-place mutation of an existing version.

#### Scenario: Consumer bootstraps from the base URL alone
@e2e exclude Machine-to-machine API contract with no UI surface; covered by DiscoveryControllerTest (document shape, no instance-private data) and the machine-secret-api Newman collection's discovery group.
- **WHEN** a machine consumer fetches the discovery document without authentication
- **THEN** the response MUST contain the token endpoint, grant type, assertion requirements, secret endpoints, and `apiVersion`
- **AND** the consumer can derive every contract URL without reading Keepiq source or configuration

### Requirement: Name-Addressable Secret Retrieval
The system MUST allow an authenticated application to retrieve a secret from its own vault by exact plaintext name via `GET /api/v1/app/secrets/by-name/{name}`, optionally scoped by a `folder` query parameter using slash-separated path notation. Zero matches MUST return 404. Exactly one match MUST return the encrypted envelope. Multiple matches MUST return 409 Conflict with a body listing each candidate's id, name, folder path, and update timestamp — the system MUST NOT silently pick one of several same-named secrets.

#### Scenario: Resolve by name
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretsControllerTest::testByNameSingleMatch and the Newman seeded by-name request.
- **WHEN** an application requests `by-name/zgw-api-token` and exactly one secret with that name exists in its vault
- **THEN** the response MUST be the encrypted envelope for that secret

#### Scenario: Ambiguous name rejected with candidates
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretsControllerTest::testByNameAmbiguousReturns409WithCandidates and testByNameFolderScoped.
- **WHEN** two secrets named `zgw-api-token` exist in the application's vault
- **THEN** the response MUST be 409 Conflict listing both candidates with id, name, folder path, and update timestamp
- **AND** a folder-scoped request matching exactly one of them MUST succeed

#### Scenario: Unknown name
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretsControllerTest::testByNameNoMatch and the Newman by-name-unknown request.
- **WHEN** an application requests a name that matches no secret in its vault
- **THEN** the response MUST be 404

### Requirement: Versioned Encrypted Response Envelope
Every machine secret read MUST return the versioned `doriath-machine-secret-v1` envelope: a `format` identifier, plaintext-safe metadata (id, name, url, folder path, type, created/updated timestamps), an `encryption` block (suite id, certificate fingerprint, scheme identifier naming the existing ADR-003 encrypt path), and the ciphertext fields (key, login, additional fields) encrypted under the application's own certificate. The server MUST return ciphertext only — machine endpoints MUST never return decrypted secret values under any condition. The certificate fingerprint MUST allow a consumer to detect a key mismatch before attempting decryption.

#### Scenario: Envelope is self-describing
@e2e exclude Machine-to-machine API contract with no UI surface; covered by MachineSecretEnvelopeServiceTest::testEnvelopeIsSelfDescribing and the Newman envelope-shape assertions.
- **WHEN** an application fetches a secret through any machine read endpoint
- **THEN** the envelope MUST identify its format version, the encryption suite, the certificate fingerprint, and the encryption scheme
- **AND** the consumer can decrypt using only the envelope and its own private key

#### Scenario: Server never returns plaintext on machine endpoints
@e2e exclude Machine-to-machine API contract with no UI surface; ciphertext-only guarantee covered by MachineSecretEnvelopeServiceTest::testEnvelopeExposesCiphertextOnly and the Newman ciphertext-only assertion — a Playwright DOM check cannot prove ciphertext-only payloads.
- **WHEN** any `/api/v1/app/secrets*` endpoint responds
- **THEN** the secret value, login, and additional fields appear only as ciphertext

### Requirement: Rotation Polling
Single-secret machine reads MUST return a strong `ETag` that changes whenever the secret's stored state changes, and MUST honor `If-None-Match` by returning 304 Not Modified without a body on a match. The application secret list MUST accept an `updated_since` ISO 8601 parameter and return only secrets updated after that instant, so consumers can detect rotated credentials with one cheap call per polling cycle.

#### Scenario: Unchanged secret returns 304
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretsControllerTest::testIfNoneMatchReturns304 + MachineSecretEnvelopeServiceTest::testEtagStableAndChanges and the Newman ETag-304 request.
- **WHEN** an application re-fetches a secret presenting the previously returned ETag and the secret has not changed
- **THEN** the response MUST be 304 with no envelope body

#### Scenario: Rotated secret detected
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretsControllerTest::testUpdatedSinceFilter and the Newman updated_since request.
- **WHEN** a secret's value is updated and the application polls the list with `updated_since` set to its last poll time
- **THEN** the list MUST include that secret
- **AND** a subsequent fetch with the stale ETag MUST return the new envelope with a new ETag

### Requirement: Application Write-Back
The system MUST allow an authenticated application to create and update secrets in its own vault (`POST /api/v1/app/secrets`, `PUT /api/v1/app/secrets/{id}`) by submitting plaintext-safe metadata plus fields already encrypted with the application's own public certificate. The server MUST validate metadata and the ciphertext envelope shape only — it can never inspect plaintext. The machine API MUST NOT allow secret deletion; deletion remains a human/administrative operation per the application-mgmt capability.

#### Scenario: Connector rotates a credential
@e2e exclude Machine-to-machine API contract with no UI surface; covered by SecretServiceMachineWriteTest::testUpdateByApplicationAdvancesTimestamps + ApplicationSecretsControllerTest::testCreateReturns201 and the Newman write-back request.
- **WHEN** an application PUTs a new client-encrypted value for a secret it owns
- **THEN** the stored ciphertext MUST be replaced and the secret's update timestamp advanced
- **AND** no plaintext value appears in the request

#### Scenario: Machine deletion refused
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretsControllerTest::testNoDeleteHandlerExists (no destroy/delete handler) and the absence of any DELETE route on /api/v1/app/secrets.
- **WHEN** an application attempts to delete a secret through the machine API
- **THEN** no delete operation exists on the machine surface and the attempt MUST fail

### Requirement: Token Endpoint Hardening
The token endpoint MUST verify the JWT assertion's signature against the application's registered certificate, reject assertions with a lifetime over 300 seconds or an expired/future validity window, and reject any reuse of a `jti` within the assertion's lifetime (replay protection). Failed exchanges MUST be subject to Nextcloud brute-force throttling. Applications that are pending, rejected, deleted, or whose EncryptionSuite is revoked or compromised MUST be refused a token. Issued bearer tokens MUST be opaque, expire within 5 minutes, and grant access to exactly one application's vault.

### Requirement: Discovery Path
The discovery document MUST be served at both the canonical path
`/api/v1/app/.well-known/keepiq` and the pre-rename path
`/api/v1/app/.well-known/doriath`, returning identical content from each. The
document MUST publish the canonical path as `discoveryPath` and each deprecated
path with the apiVersion retiring it as
`deprecatedDiscoveryPaths[].removedInAppVersion`, so a consumer configured with
the old path can re-point itself without coordination. This is the one URL a
consumer holds by hand; everything else it uses is derived from the document.

Serving the deprecated path MUST be removed before the first stable release (app version 1.0.0). Until then, every
fetch on it MUST be logged, so the set of consumers still to migrate is
observable rather than assumed.

#### Scenario: Both discovery paths serve the same document
@e2e exclude Machine-to-machine API contract with no UI surface; covered by DiscoveryControllerTest.
- **WHEN** the document is fetched from the canonical and the deprecated path
- **THEN** both MUST return 200 with identical content

### Requirement: Envelope Format Succession
The envelope `format` identifier is written by the server and asserted on by the
consumer, so unlike an inbound value it cannot be dual-valued: exactly one string
is emitted and any consumer pinned to a different one rejects the secret. The
system MUST therefore keep emitting `doriath-machine-secret-v1` until the first stable
release, and MUST publish its successor in the discovery document as
`upcomingEnvelopeFormats[]` with `value`, `replaces` and `emittedFromAppVersion`,
so consumers can be taught to accept both names before the switch. The successor
MUST NOT be listed in `envelopeFormats`, which declares only what is actually
emitted. The envelope bytes MUST NOT change with the identifier.

#### Scenario: Successor announced but not emitted
@e2e exclude Machine-to-machine API contract with no UI surface; covered by DiscoveryControllerTest and MachineSecretEnvelopeServiceTest.
- **WHEN** the discovery document is fetched before the first stable release
- **THEN** `envelopeFormats` MUST contain only `doriath-machine-secret-v1`
- **AND** `upcomingEnvelopeFormats` MUST announce `keepiq-machine-secret-v1` for app version 1.0.0

### Requirement: Assertion Audience
The token endpoint MUST accept an assertion whose `aud` claim names this
instance, honouring RFC 7519 §4.1.3: `aud` MAY be a single string or an array
of strings, and the assertion is acceptable when ANY presented value is one the
instance accepts.

The claim MUST be read strictly and MUST NOT be coerced. A value that is not a
string, and an array containing any member that is not a non-empty string, are
malformed and MUST be rejected — an array MUST NOT be filtered down to its
well-formed members, because authenticating on the remainder is exactly what a
malformed claim must not achieve.

An OBJECT-valued claim MUST be rejected, and the distinction between a JSON
array and a JSON object MUST survive decoding for that to be possible: decoding
an object into a keyed map makes `{"target": "keepiq"}` indistinguishable from
`["keepiq"]`, and a list check does not recover it, since `{"0": "keepiq"}`
decodes to a list.

Use of a deprecated audience MUST be reported only after the assertion is fully
authenticated — signature, issuer and replay checks all passed. The report
names an issuer, and before authentication that issuer is a string the caller
chose; reporting earlier would let an unauthenticated or replayed assertion
manufacture migration traffic for any issuer it named, and the log exists
precisely to decide when the deprecated value can be withdrawn. The instance MUST accept both `keepiq` (canonical) and
`doriath` (deprecated, the pre-rename name), and MUST reject any other value.

The discovery document MUST publish the canonical value as `audience`, the full
accepted set as `acceptedAudiences`, and each deprecated value together with the
apiVersion that retires it as `deprecatedAudiences[].removedInAppVersion`. This
is additive and therefore valid within the current apiVersion: an existing
consumer is unaffected, and a self-configuring consumer converges on the
canonical value without coordination.

Accepting `doriath` MUST be removed before the first stable release (app version
1.0.0), alongside the `doriath-machine-secret-v1` envelope name and the
`.well-known/doriath` path. These are pre-stable shims, not a released contract:
the rule below that breaking changes ship as a new apiVersion binds from the
first stable release onward, and `apiVersion` stays at 1 through their removal.
Until then, every assertion accepted on a deprecated value MUST be logged with
its `iss`, so the set of consumers still to migrate is observable rather than
assumed.

#### Scenario: Canonical audience accepted
@e2e exclude Machine-to-machine API contract with no UI surface; covered by JwtAuthServiceTest.
- **WHEN** an assertion presents `aud: "keepiq"`
- **THEN** the audience check MUST pass and nothing MUST be logged as deprecated

#### Scenario: Deprecated audience accepted and reported
@e2e exclude Machine-to-machine API contract with no UI surface; covered by JwtAuthServiceTest.
- **WHEN** an assertion presents `aud: "doriath"`
- **THEN** the audience check MUST pass
- **AND** a warning naming the assertion's `iss` and the removing app version MUST be logged

#### Scenario: Array-valued audience accepted
@e2e exclude Machine-to-machine API contract with no UI surface; covered by JwtAuthServiceTest.
- **WHEN** an assertion presents `aud: ["something-else", "keepiq"]`
- **THEN** the audience check MUST pass

#### Scenario: A malformed audience is rejected, not coerced
@e2e exclude Machine-to-machine API contract with no UI surface; covered by JwtAuthServiceTest.
- **WHEN** an assertion presents `aud: 123`, `aud: true` or `aud: ["keepiq", 123]`
- **THEN** the exchange MUST be rejected
- **AND** the well-formed members of a mixed array MUST NOT be matched against the accepted set

#### Scenario: An object-valued audience is rejected
@e2e exclude Machine-to-machine API contract with no UI surface; covered by JwtAuthServiceTest.
- **WHEN** an assertion presents `aud: {"target": "keepiq"}` or `aud: {"0": "keepiq"}`
- **THEN** the exchange MUST be rejected, even though an accepted value appears among the object's values

#### Scenario: A rejected assertion is never reported as a migration
@e2e exclude Machine-to-machine API contract with no UI surface; covered by JwtAuthServiceTest.
- **WHEN** an assertion carrying a deprecated audience fails signature verification or replays a `jti`
- **THEN** no deprecation warning MUST be emitted for its issuer

#### Scenario: Foreign audience rejected
@e2e exclude Machine-to-machine API contract with no UI surface; covered by JwtAuthServiceTest.
- **WHEN** an assertion presents an `aud` naming neither accepted value
- **THEN** the exchange MUST be rejected

#### Scenario: Replayed assertion rejected
@e2e exclude Machine-to-machine API contract with no UI surface; covered by JwtAuthServiceTest (jti replay) and the Newman token negative cases.
- **WHEN** the same signed assertion (same `jti`) is presented twice within its lifetime
- **THEN** the second exchange MUST be rejected

#### Scenario: Pending application refused
@e2e exclude Machine-to-machine API contract with no UI surface; covered by JwtAuthServiceTest::testInactiveApplicationRejected (status guard) and the application-mgmt isActive() check.
- **WHEN** an application with status `pending` presents a validly signed assertion
- **THEN** the token exchange MUST be refused

#### Scenario: Failed exchanges throttled
@e2e exclude Machine-to-machine API contract with no UI surface; covered by the BruteForceProtection attribute + throttle() calls on ApplicationTokenController::exchange (verified by the route-auth/semantic-auth gates) and the Newman token-negative group.
- **WHEN** repeated invalid assertions are presented
- **THEN** the endpoint MUST apply brute-force throttling to subsequent attempts

### Requirement: Strict Own-Vault Scoping
Every machine secret endpoint MUST resolve the bearer token to exactly one application and key every query by that application's identity. A request for a secret id or name belonging to a different application's vault (or to a user vault) MUST return the same 404 as a nonexistent secret — no response may reveal the existence of secrets outside the authenticated application's vault.

#### Scenario: Cross-vault access is invisible
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretsControllerTest::testCrossVaultShowReturns404 / testShowNonexistentReturns404 + SecretServiceMachineWriteTest::testUpdateByApplicationCrossVaultNotFound and the Newman cross-vault-404 request.
- **WHEN** application A requests, by id, a secret owned by application B
- **THEN** the response MUST be 404, indistinguishable from requesting a nonexistent id

### Requirement: Secret Reference Format and Consumption Contract
The system MUST document the canonical secret reference format `doriath://{applicationId}/{folderPath}/{name}` (folder path optional) and its resolution algorithm — discovery, token exchange, by-name fetch with folder scope, local decryption — in an OpenConnector consumption recipe covering private-key custody (the key is the consumer's credential, never embedded in shareable configuration), re-registration recovery after key loss, and the prohibition on logging decrypted values or bearer tokens. The contract MUST ship as an executable Newman collection (`tests/integration/machine-secret-api.postman_collection.json`) exercising discovery, token exchange (including replay and pending-application refusal), by-name resolution including the 409 case, envelope shape, ETag/`updated_since` rotation flow, write-back, and cross-vault 404 scoping — the shared verification artifact for the OpenConnector-side implementation in its own repository.

#### Scenario: Contract collection verifies the full consumer flow
@e2e exclude Machine-to-machine API contract with no UI surface; this scenario IS the Newman collection (tests/integration/machine-secret-api.postman_collection.json) — its own executable artifact, not a Playwright spec.
- **WHEN** the machine-secret-api Newman collection runs against a deployed instance with a seeded application
- **THEN** the discovery → token → by-name → decrypt-input flow, rotation polling, write-back, and every negative case (replay, pending app, ambiguity, cross-vault 404) MUST pass

#### Scenario: Reference resolves end to end
@e2e exclude Machine-to-machine API contract with no UI surface; folder-scoped resolution covered by ApplicationSecretsControllerTest::testByNameFolderScoped and documented in docs/integration-openconnector.md; the consumer-side resolver lives in the openconnector repo with its own e2e.
- **WHEN** a consumer resolves `doriath://{appId}/infra/zgw/zgw-api-token` per the documented algorithm
- **THEN** the by-name request is folder-scoped to `infra/zgw` and returns the envelope for that secret

### Requirement: Machine Secret-Request Creation

The system MUST allow a JWT-Bearer-authenticated application to create a `SecretRequest` in its OWN vault, session-lessly, via `POST /api/v1/app/secret-requests`, with the same authentication posture as the other `/api/v1/app/*` routes (the `Application` principal is resolved from the validated Bearer token by `JwtAuthMiddleware`; no Nextcloud user session is involved). The request body MUST carry a non-empty typed `requestedFields` list and MAY carry `name`, `folderPath`, and `expiresAt`. The system MUST auto-create the underlying application-owned Secret shell (owner_type `application`, owner_id = the authenticated application) so the caller never needs a pre-existing secret id, and MUST encrypt later-submitted values under the application's own registered certificate. The response MUST return the created request including its `token` and the derived public fill-link URL. The endpoint MUST NOT accept a `userId` or any actor other than the authenticated application.

#### Scenario: Application creates a request in its own vault via the machine route
@e2e exclude Machine-to-machine JWT-Bearer API with no UI surface; covered by ApplicationSecretRequestsControllerTest (create → 201 with token + fill-link) and section 6 of the machine-secret-api Newman collection.
- **GIVEN** an approved application with an active EncryptionSuite presents a valid Bearer token
- **WHEN** it POSTs `requestedFields` (and optional `name`/`folderPath`/`expiresAt`) to `/api/v1/app/secret-requests`
- **THEN** the system MUST create an application-owned Secret shell and a pending `SecretRequest` with a unique token
- **AND** the response MUST include the `token` and the derived public fill-link URL

#### Scenario: Created request is fillable via the existing public fill flow
@e2e exclude Machine-to-machine API plus token-based public fill flow; covered by ApplicationSecretRequestsControllerTest + SecretRequestFillControllerTest, not Playwright.
- **GIVEN** an application created a request through the machine route and holds its `token`
- **WHEN** an external party opens `GET /api/v1/public/secret-requests/{token}` and submits values via `POST /api/v1/public/secret-requests/{token}/fill`
- **THEN** the submitted values MUST be encrypted with the application's public certificate and stored in the linked Secret
- **AND** the request status MUST become fulfilled

#### Scenario: userId or foreign actor is refused on the machine route
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestsControllerTest::testUserIdInBodyCannotRedirectTheVault.
- **WHEN** a machine-route request body attempts to set an actor (`userId`) other than the authenticated application
- **THEN** the request MUST still be created only in the authenticated application's vault, never for the supplied actor

### Requirement: Machine Pending-Request Listing

The system MUST allow a JWT-Bearer-authenticated application to list its own PENDING secret requests / open fill-links via `GET /api/v1/app/secret-requests`, scoped strictly to the caller's vault, so a fill-link is retrievable after creation. The listing MUST include, per request, the `token` and the derived public fill-link URL alongside the request metadata. It MUST NEVER include requests belonging to another application's or a user's vault.

#### Scenario: Application lists its own pending fill-links
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestsControllerTest::testIndexReturnsOwnPendingRequests and the section-6 Newman list request.
- **GIVEN** an application created one or more pending requests
- **WHEN** it GETs `/api/v1/app/secret-requests` with a valid Bearer token
- **THEN** the response MUST list those pending requests each with its `token` and fill-link URL
- **AND** MUST NOT list any request outside the authenticated application's vault

### Requirement: Machine Request Creation Own-Vault Scoping

Every machine secret-request operation MUST be keyed by the authenticated application's identity. A created request MUST be created ONLY in the authenticated application's vault; the surface MUST NOT expose any parameter that lets it target another application's or a user's vault. Listing another vault's requests MUST return the same empty/404 semantics as the read path — no response may reveal the existence of requests outside the authenticated application's vault (no existence oracle).

#### Scenario: Request creation cannot target another vault
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestsControllerTest::testUserIdInBodyCannotRedirectTheVault (the vault comes from the verified principal, so no body parameter can target another) and ApplicationSecretRequestServiceTest::testListPendingForApplicationVaultScopesAndFilters.
- **GIVEN** application A holds a valid Bearer token
- **WHEN** A attempts, by any body parameter, to create a request in application B's or a user's vault
- **THEN** the request MUST be created only in A's vault, or refused — never created for B or a user
- **AND** the outcome MUST NOT reveal whether B's vault or the named foreign secret exists

### Requirement: Machine Request Creation Hardening and Audit

Machine secret-request creation MUST enforce the same guards as token issuance: it MUST be refused for pending, rejected, or deleted applications and for applications whose EncryptionSuite is revoked or compromised. Creation MUST emit an audit event with the application as actor (parity with `application.token_issued` / `application.secret_retrieved`). The machine surface MUST NOT expose any way to read back submitted plaintext — the write-without-read property of `SecretRequest` is preserved.

#### Scenario: Creation refused for a revoked suite or non-approved application
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestsControllerTest::testCreationRefusedForRevokedSuite and ApplicationSecretRequestServiceTest::testCreationRefusedWhenTheSuiteIsNotActive; the pending/rejected/deleted half is enforced at AUTHENTICATION (JwtAuthService::loadActiveIssuer, an allow-list on STATUS_ACTIVE) and covered by JwtAuthServiceTest::testInactiveApplicationRejected, so such an application never reaches this surface.
- **GIVEN** an application that is pending/rejected/deleted OR whose EncryptionSuite is revoked/compromised
- **WHEN** it attempts to create a secret request on the machine surface
- **THEN** the creation MUST be refused

#### Scenario: Creation emits an audit event
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestServiceTest::testCreationEmitsExactlyOneApplicationAuditEvent asserting the dispatched typed audit event.
- **WHEN** an application successfully creates a request on the machine surface
- **THEN** exactly one audit event MUST be dispatched with the application as actor
