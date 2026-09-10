## Why

A compromise-recovery rotation silently destroys the user's emergency-access recovery. `EmergencyAccessSuiteRotationListener` fires on `SuiteMigrationCompletedEvent` and calls `invalidateForGrantorRotation()`, which clears the recovery envelope of every emergency contact bound to the old suite. So the one pre-arranged break-glass path a careful user set up is gone after a routine key change, and `CompromiseRecoveryForm.vue` never tells them to re-establish it (verified — the form has no emergency-access copy). This was raised as an open question in the `harden-vault-key-material-guards` change and is the natural fix for it.

The `encryption-suites` spec presents this destruction as unavoidable. The *Migration Covers Every Suite-Bound Store* requirement says of `keepiq_emergency_contacts`:

> "the rotating owner cannot re-wrap it alone; the grantor MUST be prompted to re-establish emergency access."

**That justification is wrong**, and the code proves it. The recovery envelope is built by `buildRecoveryEnvelope(privateKeyPem, granteeCertificatePem)` (`src/crypto/emergencyEnvelope.js`). Re-wrapping the *old* envelope would indeed need the grantee's key — but nobody needs to re-wrap the old one. During a rotation the owner mints a *fresh* envelope escrowing the **new** private key, and both inputs are already in hand:

- `newPrivateKeyPem` — generated in the browser at the top of `initiateCompromiseRecovery` (`src/store/modules/encryptionSuite.js:186`), the same value that seals every other store in the migration;
- the grantee's current certificate — fetchable via `getGranteeCertificate()`, exactly as initial designation fetches it.

This is byte-for-byte the operation designation already performs, and structurally identical to the attachment-grant disposition one row up in the same table ("Re-wrap the rotating owner's own grants under the new suite"). Emergency contacts are simply one more suite-bound store that can **migrate** rather than being invalidated.

Scope is compromise recovery only. A routine master-password change keeps the same RSA key pair and only re-wraps the AES envelope, so the escrowed private key is unchanged and existing recovery envelopes still open — routine change neither invalidates nor needs to migrate them, and the invalidation listener does not fire for it.

This change also carries the destructive-revocation safeguard from #395's lost-password route. It belongs here rather than in the guard change (`harden-vault-key-material-guards`): once that guard blocks a forgotten-password rotation, administrator revocation becomes the only way back to a working vault, and revocation *deletes* emergency access — so the warning and the ordering gate are emergency-access-lifecycle behaviour, adjacent to the rotation-migration this change already owns.

## What Changes

- Migrate emergency-access recovery envelopes as part of compromise-recovery migration: for each of the rotating owner's emergency contacts still bound to the old suite whose grantee has a usable certificate, the browser builds a fresh recovery envelope escrowing the **new** private key, wrapped to the grantee's current certificate, and re-points the contact to the new suite — leaving its `granted` state intact
- Correct the *Migration Covers Every Suite-Bound Store* disposition for `keepiq_emergency_contacts` from "Invalidate, unchanged" to "Re-envelope under the new key where the grantee is reachable; invalidate only the residual"
- Keep `invalidateForGrantorRotation()` as a **fallback sweep**: after migration, it now finds only the contacts that could not be re-enveloped (grantee has no active suite / left the instance), which are exactly the ones that genuinely must be invalidated
- Surface the residual: where any contact was invalidated rather than migrated, prompt the owner to re-designate that specific contact — replacing today's silent, total loss with a targeted, explained one
- Fold in the destructive-revocation safeguard for the lost-password route: revoking a user suite still clears its emergency envelopes, but the system now MUST warn plainly (secrets gone, emergency access **deleted**, accessor must retrieve first while the suite is active), MUST refuse while a usable emergency contact exists unless an explicit override is given, and MUST surface the count of usable contacts (never identities) so the administrator can choose. Today `clearForGrantorRevocation` deletes them silently
- Do **not** change the routine master-password-change flow, which does not rotate the key pair

## Capabilities

### Modified Capabilities
- `encryption-suites`: the *Migration Covers Every Suite-Bound Store* requirement gains emergency contacts as a migrated store rather than an invalidated one, with a defined residual disposition
- `emergency-access`: *Envelope Invalidation on Key Change* changes from "rotation invalidates every envelope" to "rotation re-envelopes under the new key where possible and invalidates only the residual"

## Impact

- **Database**: none. Re-enveloping reuses the existing `recovery_envelope` and `grantor_suite_id` columns of `keepiq_emergency_contacts`; no schema change, no migration, no `<version>` bump
- **Backend**: `getWork` (or a sibling read) exposes the owner's emergency contacts still bound to the old suite, with the `granteeUserId` needed to fetch the certificate; a migration commit endpoint accepts a fresh envelope and re-points `grantor_suite_id` to the new suite while keeping `state = granted`; `EmergencyAccessSuiteRotationListener` is unchanged in code but now runs as a residual sweep. Owner/suite scoping enforced server-side exactly as the other migration writes are
- **Frontend**: `initiateCompromiseRecovery` builds a new envelope per reachable contact using `buildRecoveryEnvelope(newPrivateKeyPem, granteeCert)` and commits it in the migration loop; the completion summary lists any residual contacts to re-designate; `CompromiseRecoveryForm.vue` renders that prompt
- **Security**: unchanged trust model. The new envelope escrows the new private key and is wrapped to the grantee's public certificate; the raw private key exists only transiently in the browser, and only ciphertext crosses the wire (ADR-003). Binding to the grantee's *current* certificate is strictly more correct than the old envelope, which may have escrowed a key the grantee has since rotated away from
- **Revocation path**: the user-suite revoke flow gains the usable-contact check and the override parameter; the warning copy lives in the settings dialog. `clearForGrantorRevocation` is unchanged in effect (still clears on the override path) but no longer reachable silently
- **Cross-app**: none
- **Dependency note**: composes with `harden-vault-key-material-guards` but does not require it. That change gates *destructive* operations; this one makes a *legitimate* rotation preserve emergency access. Landing this resolves that change's third open question
