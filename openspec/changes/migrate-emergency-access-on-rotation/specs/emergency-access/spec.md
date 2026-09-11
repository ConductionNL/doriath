## MODIFIED Requirements

### Requirement: Envelope Invalidation on Key Change
Because the recovery envelope escrows the grantor's private key as of designation, a change to that key MUST be reflected in the envelopes bound to it.

When the grantor's EncryptionSuite is rotated (compromise recovery), the system MUST migrate each affected recovery envelope where the grantee is reachable: it MUST build a fresh envelope escrowing the grantor's **new** private key, sealed to the grantee's current certificate, and re-point the contact to the new suite while preserving its `granted` state. A contact whose grantee has no active certificate to seal to (the grantee left the instance or revoked their suite) cannot be migrated; the system MUST invalidate that residual contact and MUST prompt the grantor to re-establish it. The grantor MUST NOT be required to open the old envelope to do any of this — building a new envelope needs only the new private key, which the grantor holds during rotation, and the grantee's public certificate.

Migrating rather than invalidating is possible because the recovery envelope is rebuilt, not re-wrapped: `buildRecoveryEnvelope` takes the grantor's private key and the grantee's public certificate, both of which the grantor has mid-rotation. Sealing to the grantee's *current* certificate is also more correct than preserving the old envelope, which may escrow a key the grantee has since rotated away from.

When the grantor's EncryptionSuite is revoked, existing recovery envelopes MUST be cleared. Revocation is not a key rotation and produces no new key to migrate to, so unlike rotation there is nothing to migrate the envelope to. But clearing is destructive and irreversible — `clearForGrantorRevocation` deletes the rows outright — and revocation of a user suite is the last-resort route for an owner who has lost their master password, exactly the owner most likely to still need their emergency contact. The system MUST therefore treat this clearing as a decision the acting administrator makes knowingly, not a silent side effect:

- Before revoking a user suite that has a usable (non-invalidated) emergency contact, the system MUST warn plainly that every secret becomes permanently unreadable and the vault is rebuilt from scratch, that the designated emergency access is **deleted** along with it, and that if an emergency accessor exists they MUST retrieve the old secrets first, while the old suite is still `active`.
- The system MUST refuse the revocation while a usable emergency contact exists, unless the caller supplies an explicit override. The refusal MUST surface the **count** of usable contacts so the administrator can choose — never their identities, which stay grantor-private. Today the deletion is silent and the count is not surfaced; that is the gap this closes.
- With the override, revocation proceeds and clears the envelopes as before. The ordering is enforceable, not merely documented.

Likewise, if a grantee's EncryptionSuite is revoked, envelopes encrypted to that grantee MUST be invalidated; this is unchanged.

#### Scenario: Suite rotation migrates a reachable contact
@e2e exclude Server-side re-point plus client-side envelope construction; verifying the migrated envelope opens requires the grantee's key in a second browser context. Covered by PHPUnit on the re-point endpoint and unit tests of the envelope builder.
- **GIVEN** A has an emergency contact B whose EncryptionSuite is active
- **AND** a recovery envelope escrowing A's current private key
- **WHEN** A performs compromise recovery and rotates their EncryptionSuite
- **THEN** the system MUST build a fresh recovery envelope escrowing A's new private key, sealed to B's current certificate
- **AND** re-point the contact to A's new suite with its state still `granted`
- **AND** MUST NOT prompt A to re-establish B

#### Scenario: Suite rotation invalidates only the unreachable residual
@e2e exclude Server-side listener sweep after the migration loop; covered by PHPUnit (contacts remaining on the old suite are invalidated) and the completion-summary assertion.
- **GIVEN** A has emergency contacts B (active suite) and C (no active suite)
- **WHEN** A performs compromise recovery and rotates their EncryptionSuite
- **THEN** B MUST be migrated to the new suite
- **AND** C MUST be invalidated
- **AND** A MUST be prompted to re-establish C specifically

#### Scenario: Revocation refuses while a usable emergency contact exists
@e2e exclude Server-side guard on the revoke path; covered by PHPUnit asserting revocation is refused and the usable-contact count is returned. Live UI run deferred.
- **GIVEN** A has a usable (non-invalidated) emergency contact
- **WHEN** an administrator revokes A's suite without an override
- **THEN** the system MUST refuse and MUST report the count of usable emergency contacts
- **AND** MUST NOT clear any recovery envelope or change the suite status
- **AND** MUST NOT disclose the contact's identity

#### Scenario: Revocation proceeds with an explicit override and warns
@e2e exclude Server-side guard plus the destructive clear; covered by PHPUnit on the revoke path with the override flag. The warning copy is asserted in the settings-dialog component test.
- **GIVEN** A has a usable emergency contact and the administrator has been shown the destruction warning
- **WHEN** the administrator revokes A's suite with the explicit override
- **THEN** the suite MUST be revoked and the recovery envelopes cleared
- **AND** the warning MUST have stated that emergency access is deleted and that an accessor must retrieve secrets first while the suite is still active

#### Scenario: Suite revocation clears envelopes
@e2e exclude Server-side suite rotation/revocation listener contract — covered by PHPUnit (invalidateForGrantorRotation/clearForGrantorRevocation/invalidateForGranteeRevocation + invalidated audit). Live UI run deferred (worktree not deployed).
- **GIVEN** A has one or more emergency contacts with recovery envelopes
- **WHEN** A's EncryptionSuite is revoked
- **THEN** the recovery envelopes MUST be cleared
