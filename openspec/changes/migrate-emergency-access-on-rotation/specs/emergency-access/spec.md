## MODIFIED Requirements

### Requirement: Envelope Invalidation on Key Change
Because the recovery envelope escrows the grantor's private key as of designation, a change to that key MUST be reflected in the envelopes bound to it.

When the grantor's EncryptionSuite is rotated (compromise recovery), the system MUST migrate each affected recovery envelope where the grantee is reachable: it MUST build a fresh envelope escrowing the grantor's **new** private key, sealed to the grantee's current certificate, and re-point the contact to the new suite while preserving its `granted` state. A contact whose grantee has no active certificate to seal to (the grantee left the instance or revoked their suite) cannot be migrated; the system MUST invalidate that residual contact and MUST prompt the grantor to re-establish it. The grantor MUST NOT be required to open the old envelope to do any of this — building a new envelope needs only the new private key, which the grantor holds during rotation, and the grantee's public certificate.

Migrating rather than invalidating is possible because the recovery envelope is rebuilt, not re-wrapped: `buildRecoveryEnvelope` takes the grantor's private key and the grantee's public certificate, both of which the grantor has mid-rotation. Sealing to the grantee's *current* certificate is also more correct than preserving the old envelope, which may escrow a key the grantee has since rotated away from.

When the grantor's EncryptionSuite is revoked, existing recovery envelopes MUST be cleared. Revocation is not a key rotation and produces no new key to migrate to; this is unchanged. Likewise, if a grantee's EncryptionSuite is revoked, envelopes encrypted to that grantee MUST be invalidated; this is unchanged.

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

#### Scenario: Suite revocation clears envelopes
@e2e exclude Server-side suite rotation/revocation listener contract — covered by PHPUnit (invalidateForGrantorRotation/clearForGrantorRevocation/invalidateForGranteeRevocation + invalidated audit). Live UI run deferred (worktree not deployed).
- **GIVEN** A has one or more emergency contacts with recovery envelopes
- **WHEN** A's EncryptionSuite is revoked
- **THEN** the recovery envelopes MUST be cleared
