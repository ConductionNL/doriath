## MODIFIED Requirements

### Requirement: Master Password Change — Routine
The system MUST allow a user to change their master password for routine hygiene reasons. In this case, the RSA key pair MUST remain unchanged — only the AES wrapping of the private key changes.

Replacing the stored private-key envelope MUST require a verified key proof (see the `vault-key-proof` capability). The envelope is the only copy of the private key, so a request that replaces it with material the owner cannot open destroys the vault in a single call; an ownership check alone is therefore insufficient authority.

The proof MUST be bound to the submitted envelope, and MUST be verified against the public key of the suite named in the route.

The flow already holds the current master password in order to derive the old AES key, so the proof imposes no additional prompt: the raw private key is materialised at exactly the moment the signature must be produced. An implementation that re-wraps the envelope without materialising the old private key would be unable to produce the proof and MUST NOT be adopted.

#### Scenario: Routine password change
@e2e exclude The password-change form is rendered inside the user-settings dialog; verifying that AES key re-wrapping succeeded requires reading back the encrypted private-key blob — a crypto-API assertion, not DOM-observable. The form's UI surface is captured in user-settings::user-opens-settings.
- GIVEN a user provides their current master password and a new master password
- AND the new master password meets the configured strength floor
- WHEN the change is submitted
- THEN the system MUST decrypt the private key using the current AES-derived key
- AND re-encrypt it using the new AES-derived key
- AND store the updated blob
- AND no secrets are affected

#### Scenario: Envelope replacement without a key proof is refused
@e2e exclude Middleware enforcement on a session-authenticated route; not DOM-observable. Covered by PHPUnit on the middleware and the attribute-coverage test.
- **GIVEN** an authenticated session for the suite owner
- **WHEN** a replacement private-key envelope is submitted without a verified key proof
- **THEN** the system MUST refuse with `403` and `error: key_proof_required`
- **AND** the stored envelope MUST be unchanged

### Requirement: Master Password Change — Compromise Recovery
When a user indicates their master password has been compromised, the system MUST initiate a full key rotation: a new RSA key pair is generated, all secrets are re-encrypted, and the old EncryptionSuite is flagged as compromised.

Initiating compromise recovery MUST require a verified key proof over the **old** suite's private key (see the `vault-key-proof` capability). The proof MUST be bound to the submitted successor public key and successor private-key envelope.

Without it, the operation proves nothing about the suite it replaces: any holder of the owner's session can submit their own key pair, become the write target by suite resolution, and reach a terminal state that locks the old suite. Every downstream variant of that attack — reporting records unrecoverable, or committing ciphertext the owner cannot open — is reachable only through this entry point, so this is where the gate belongs. Gating completion alone is insufficient, because a caller who commits ciphertext for every record produces zero failures and needs no acknowledgement.

Requiring the proof does not obstruct legitimate recovery: rotation exists for a key that may be **exposed**, not for a password that was **forgotten**, so a user rotating still knows their master password. A user who has genuinely lost it MUST be routed to administrator revocation instead, which produces an empty vault and is not a recovery.

#### Scenario: Compromise recovery initiated
@e2e exclude Verifying RSA key pair generation, SuiteMigration record creation, and write-lock application requires inspecting server-side crypto state — not DOM-observable. The recovery UI form renders in the user-settings dialog and its presence is captured in user-settings::user-opens-settings.
- GIVEN a user selects "my master password was leaked" as the reason for changing their password
- AND provides their old master password and a new master password
- WHEN the change is submitted
- THEN the system MUST generate a new RSA key pair and EncryptionSuite
- AND create a SuiteMigration record with status `in_progress`
- AND apply a write lock to the account (no create/update operations on secrets)
- AND lock all pending SecretRequests (see secret-requests spec)
- AND begin migrating all secrets from the old suite to the new suite

#### Scenario: Recovery without proof of the old key is refused
@e2e exclude Middleware enforcement on a session-authenticated route; not DOM-observable. Covered by PHPUnit on the middleware and the attribute-coverage test.
- **GIVEN** an authenticated session for a user with an active EncryptionSuite
- **WHEN** compromise recovery is requested with key material not accompanied by a verified proof over the existing suite's private key
- **THEN** the system MUST refuse with `403` and `error: key_proof_required`
- **AND** MUST NOT create a successor suite, a migration record, or a write lock

### Requirement: A Migration Always Has A Way To Terminate

A migration MUST always have a way to terminate. Completion is therefore gated on rows nobody has attempted, NOT on every row still bound to `old_suite_id`. The two are different situations and conflating them makes the write lock inescapable: a record that can never be re-encrypted would hold the migration open forever, leaving the owner permanently unable to write to their own vault.

Termination MUST be reachable in both directions. Completion carries the migration forward to the new suite; **abort** returns it to the old suite. A migration that can only be completed is not terminable in the sense this requirement intends, because the only available exit is the destructive one — which is what made a hostile or abandoned rotation unrecoverable.

A row is **unaccounted for** when it is still bound to `old_suite_id` and its owning secret carries no `migration_error`. The system MUST refuse to terminate a migration while any unaccounted-for row exists, because terminating locks the old suite and would take every un-reached row down with it. The refusal MUST name the remaining count and point at resuming, and MUST point at aborting when aborting is still available.

A row that was attempted and recorded a failure MUST NOT block termination. Terminating with such rows present MUST require an explicit acknowledgement from the client stating how many records it accepts losing, and the count MUST match what the server observes; an absent or mismatched acknowledgement MUST be refused. This makes locking a secret out of the vault a decision the owner made, never a side-effect of a client calling completion — a run in which every record failed would otherwise silently lock an owner out of everything.

Completion MUST additionally require a verified key proof (see the `vault-key-proof` capability). The acknowledgement establishes that the owner accepts the loss; the proof establishes that the caller is the owner. These answer different questions and the system MUST require both.

Only a failure to decrypt the EXISTING ciphertext with the old key may be recorded as a per-record failure. A re-encryption that does not survive its round-trip check MUST NOT be recorded, because the original decrypted successfully and is therefore readable: the fault lies in the new key material, it will recur on every record, and the run MUST stop instead. It follows that finalisation can only ever remove access from rows that were already unreadable under the old key.

#### Scenario: Unattempted rows refuse termination and point at resuming

@e2e exclude Server-side query and status transition; covered by PHPUnit on the completion path.
- **GIVEN** a migration whose client stopped before processing every record, leaving rows with no `migration_error`
- **WHEN** completion is requested
- **THEN** the server MUST refuse, MUST leave the old suite `active`, and MUST keep the migration `in_progress`
- **AND** the refusal MUST report how many records remain and state that the migration can be resumed

#### Scenario: An unrecoverable record does not trap the vault

@e2e exclude Terminal status transition and suite locking are server-side; covered by PHPUnit on the completion path.
- **GIVEN** a migration in which every remaining row on `old_suite_id` has a recorded `migration_error`
- **WHEN** completion is requested WITHOUT an acknowledgement
- **THEN** the server MUST refuse and MUST state how many records would lose access
- **WHEN** completion is requested WITH an acknowledgement matching that count and a verified key proof
- **THEN** the migration MUST terminate as `completed_with_errors`, the old suite MUST be locked, and the write lock MUST be released
- **AND** the response MUST identify the secrets that lost access

#### Scenario: A round-trip failure halts rather than sacrificing the record

@e2e exclude Injected at the crypto layer; no DOM path induces it. Covered by unit tests of the migration pipeline.
- **GIVEN** a record whose existing ciphertext decrypts correctly but whose re-encryption does not survive the round-trip check
- **WHEN** the migration processes that record
- **THEN** the failure MUST NOT be recorded as a per-record migration failure
- **AND** the run MUST stop so the new key material can be investigated
- **AND** records already committed MUST remain valid, each having been verified before its own commit

## ADDED Requirements

### Requirement: A Migration Can Be Aborted Before Any Record Moves

The system MUST provide a route to abort a migration in progress, and `compromise-recovery`'s refusal message MUST NOT name a remedy that does not exist.

Abort MUST be permitted only while no record has been committed to the new suite. Once any record has moved, the two available outcomes both lose data — revoking the successor strands what has moved, keeping it active strands what has not — so the system MUST refuse to abort, MUST name the number of records already committed, and MUST point at resuming instead.

Restricting abort this way is sufficient for the case it exists to remedy: producing valid re-encrypted ciphertext requires the plaintext, and therefore the master password, so a caller who cannot prove possession of the old key can never have committed a record.

On abort the system MUST:

- set the migration to the terminal status `aborted`
- leave the old EncryptionSuite `active`, and leave every record bound to it untouched
- discard the successor suite by **deleting** it — created moments ago, it holds no ciphertext and has no shares or emergency contacts, so it is removed outright. It MUST NOT be revoked through the ordinary suite-revocation path: that path treats a revoked *user* suite as a lost identity and cascades a share-target sweep and delegation promotion, which would destroy the owner's incoming shares over a migration the abort exists to undo
- release the write lock and unlock the SecretRequests locked when the migration started
- clear the migration's failure accounting, so a later migration does not inherit a stale acknowledgement threshold

Abort MUST NOT dispatch the migration-completed event. That event is what invalidates the owner's emergency-access recovery envelopes, and abort exists precisely to avoid that loss.

Abort MUST NOT require a key proof. It is restorative — it returns the vault to a suite that is still `active` and readable — and requiring proof of a key would leave a wedged vault wedged, including one wedged by a rotation the owner never authorised. A caller who aborts another user's legitimate rotation causes a nuisance the owner can simply repeat, which is not comparable to permanent loss.

#### Scenario: Aborting an untouched migration restores the old suite

@e2e exclude Terminal status transition, suite status and write-lock release are server-side. Covered by PHPUnit on the abort path.
- **GIVEN** a migration `in_progress` with no record committed to the new suite
- **WHEN** abort is requested by the owner
- **THEN** the migration MUST become `aborted`
- **AND** the old suite MUST remain `active` with every record still bound to it
- **AND** the successor suite MUST be deleted (not revoked, which would cascade the user-suite revocation side effects)
- **AND** the write lock MUST be released and locked SecretRequests MUST be unlocked

#### Scenario: Aborting after records have moved is refused

@e2e exclude Server-side query and status transition. Covered by PHPUnit on the abort path.
- **GIVEN** a migration in which at least one record has been committed to the new suite
- **WHEN** abort is requested
- **THEN** the system MUST refuse, MUST keep the migration `in_progress`
- **AND** MUST report how many records have already been committed and state that the migration can be resumed

#### Scenario: Abort does not destroy emergency access

@e2e exclude Event dispatch and listener side effects are server-side. Covered by PHPUnit asserting the completed event is not dispatched and envelopes are unchanged.
- **GIVEN** an owner with a designated emergency contact and a migration `in_progress` with no record committed
- **WHEN** the migration is aborted
- **THEN** the emergency-access recovery envelopes MUST be unchanged and still usable
