## MODIFIED Requirements

### Requirement: Migration Covers Every Suite-Bound Store

The Suite Migration requirement speaks of migrating "all secrets". Because a user's ciphertext is bound to an EncryptionSuite in six separate stores, a migration that walks `keepiq_secrets` alone silently strands the other five. The system MUST therefore treat compromise-recovery migration as complete only when every suite-bound store has been given its disposition. Outstanding work MUST be derivable server-side from the data itself — rows still bound to `old_suite_id` — rather than from a client-reported count, so that a resumed migration knows what remains without trusting the browser.

The disposition of each store is fixed as follows. All fields listed as re-encrypted are stored as RSA ciphertext; plaintext columns (`name`, `url`, `folder_id`, `requested_fields`) are organisational metadata and MUST NOT be touched.

| Store | Suite-bound content | Disposition |
|-------|---------------------|-------------|
| `keepiq_secrets` | `key`, `login`, `additional_fields` | Re-encrypt under the new suite; re-point `encryption_suite_id` |
| `keepiq_secret_versions` | `key`, `login`, `additional_fields` (own `encryption_suite_id`) | Re-encrypt the bounded window fixed by the `secret-version-history` spec (head plus the N most recent versions, default 5); drop older versions |
| `keepiq_attachment_grants` | `wrapped_file_key` (RSA-wrapped per-file AES key) | Re-wrap the rotating owner's own grants under the new suite. Grants belonging to other recipients MUST NOT be altered |
| `keepiq_secret_requests` | No ciphertext of its own; `encryption_suite_id` selects the certificate used to encrypt future submissions | Lock for the duration of the migration, then unlock and re-point to the new suite |
| `keepiq_link_shares` | `encrypted_secret_snapshot` | Revoke (cascade), unchanged from current behaviour |
| `keepiq_emergency_contacts` | `recovery_envelope` | Re-envelope under the new key where the grantee is reachable, then invalidate only the residual. For each contact still bound to the old suite whose grantee has an active certificate, the browser builds a fresh envelope escrowing the **new** private key sealed to that certificate and re-points `grantor_suite_id` to the new suite, keeping `state = granted`. A contact whose grantee has no active suite is invalidated and the grantor is prompted to re-establish it. This is not a re-wrap of the old envelope — `buildRecoveryEnvelope` needs only the new private key (held during rotation) and the grantee's public certificate (see the `emergency-access` spec) |

Re-encryption of `keepiq_secrets`, `keepiq_secret_versions` and `keepiq_attachment_grants` MUST happen in the browser under the same rules as ordinary migration: the old private key decrypts and the new public key encrypts, both as WebCrypto `CryptoKey` objects, and only ciphertext crosses the wire. Emergency contacts are the one migrated store not produced by decrypt-then-re-encrypt: the browser builds a fresh recovery envelope from the new private key and the grantee's fetched certificate, so no old-key decrypt is involved. Unlike the three re-encrypted stores, emergency contacts MUST NOT gate completion — a contact whose grantee is unreachable can never be re-enveloped, and gating on it would make the write lock inescapable; such contacts are swept into invalidation at completion instead. RSA has a per-chunk plaintext cap (446 bytes at RSA-4096), so every value MUST be re-chunked against the new key rather than having its existing chunk framing reused.

Owner and suite scoping MUST be enforced server-side on every re-encryption write, resolving the acting user through the Nextcloud `OCP\IUserSession` the surrounding controllers already use: a write MUST be refused unless the target row's current `encryption_suite_id` is the migration's `old_suite_id` and the row is owned by the migration's owner.

#### Scenario: Attachment grants survive the rotation

@e2e exclude Attachment-grant re-wrapping is verified by unwrapping the file key with the new private key — a WebCrypto/DB assertion with no DOM surface; covered by unit tests of the migration driver and PHPUnit on the re-point endpoint.
- **GIVEN** a user owns a secret with an encrypted attachment, and their own attachment grant holds the file key wrapped under their old suite
- **WHEN** compromise recovery migration completes
- **THEN** the owner's grant MUST hold the same file key re-wrapped under the new suite and the shared ciphertext blob MUST NOT be re-uploaded or duplicated
- **AND** grants held by other recipients of that attachment MUST be unchanged

#### Scenario: Version history migrates within its bounded window

@e2e exclude Version-history migration is asserted on stored ciphertext and row counts; the version list UI shows only counts, so the migration itself is not DOM-observable. Covered by PHPUnit and migration-driver unit tests.
- **GIVEN** a secret with a head and 12 prior versions, and a migration window of 5
- **WHEN** compromise recovery migration completes
- **THEN** the head and the 5 most recent versions MUST be re-encrypted under the new suite and re-pointed
- **AND** the 7 older versions MUST be deleted
- **AND** the user MUST be told that older version history was dropped

#### Scenario: Secret requests are locked and re-pointed, not stranded

@e2e exclude The lock/re-point transition is server-side request state; the fill-in page's "temporarily unavailable" surface belongs to the secret-requests spec. Covered by PHPUnit on the request lifecycle.
- **GIVEN** a user has pending SecretRequests when they declare their master password compromised
- **WHEN** the migration starts
- **THEN** those requests MUST be set to `locked` and the fill-in link MUST report the request as temporarily unavailable
- **WHEN** the migration terminates
- **THEN** those requests MUST be unlocked and their `encryption_suite_id` MUST be the new suite

#### Scenario: A store left unprocessed blocks completion

@e2e exclude Outstanding-work detection is a server-side query with no DOM representation beyond the aggregate progress indicator; covered by PHPUnit on the completion endpoint.
- **GIVEN** a migration in which the attachment-grant pass has not yet run, so grants remain bound to `old_suite_id`
- **WHEN** the client requests completion of the migration
- **THEN** the server MUST refuse to mark the migration terminal
- **AND** the migration MUST remain `in_progress` with the write lock held

#### Scenario: A reachable emergency contact is re-enveloped, not invalidated

@e2e exclude Client builds the envelope and the server re-points the row; verifying the envelope opens needs the grantee's key in a second context. Covered by PHPUnit on the re-point endpoint and unit tests of the envelope builder.
- **GIVEN** a rotating owner with an emergency contact whose grantee has an active suite
- **WHEN** the migration processes emergency contacts
- **THEN** a fresh recovery envelope escrowing the new private key MUST be built and the contact re-pointed to the new suite with `state = granted`
- **AND** the contact MUST NOT be invalidated
- **AND** the completion MUST NOT gate on that contact

#### Scenario: An unreachable emergency contact does not trap the vault

@e2e exclude Server-side listener sweep after the loop; covered by PHPUnit asserting the residual is invalidated and completion still terminates.
- **GIVEN** a rotating owner with an emergency contact whose grantee has no active suite
- **WHEN** the migration processes emergency contacts and then completes
- **THEN** that contact MUST be invalidated by the completion sweep
- **AND** completion MUST NOT be blocked by it
- **AND** the owner MUST be prompted to re-establish that specific contact
