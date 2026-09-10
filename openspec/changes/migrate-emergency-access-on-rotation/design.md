# Design — migrate-emergency-access-on-rotation

## Context

Six stores bind ciphertext to an EncryptionSuite. Five are migrated in the browser during compromise recovery: each record's ciphertext is decrypted with the old private key and re-encrypted to the new one, committed one row per request, and the completion gate refuses until nothing remains on `old_suite_id`. The sixth — `keepiq_emergency_contacts` — is the exception: its recovery envelope is not migrated but *invalidated* by `EmergencyAccessSuiteRotationListener` when `SuiteMigrationCompletedEvent` fires.

The stated reason is that the owner "cannot re-wrap it alone". That is true of the *old* envelope, whose plaintext is the old private key sealed to the grantee's certificate — opening it needs the grantee's key. It is false of the operation actually wanted: minting a *new* envelope. `buildRecoveryEnvelope(privateKeyPem, granteeCertificatePem)` needs only the grantor's private key and the grantee's public certificate, and during a rotation the owner has both — the new private key is generated locally at `initiateCompromiseRecovery` and the grantee certificate is fetchable. Emergency contacts can therefore migrate like the other stores; the only structural difference is that the job *builds* a value from the new key rather than *transforming* an existing ciphertext.

## Goals / Non-Goals

**Goals**
- A compromise-recovery rotation preserves emergency access for every contact whose grantee is still reachable
- The residual — contacts whose grantee cannot be reached — is invalidated as today, but surfaced so the owner can re-designate, instead of lost silently
- No schema change, no new trust assumption

**Non-Goals**
- Touching the routine master-password-change flow. It keeps the same key pair, so the escrowed private key stays valid and envelopes keep opening; there is nothing to migrate and the invalidation listener does not fire for it
- Migrating a contact to a grantee who has no active suite. The envelope has nowhere to be sealed; that contact is residual by definition
- Preserving the *grantee* side of emergency access when the grantee rotates. That is governed by `invalidateForGranteeRevocation` and is out of scope

## Decisions

### D1: Re-envelope in the migration loop, mirroring attachment-grant re-wrap

Emergency contacts join the migration work list. For each contact still bound to the old suite, the browser fetches the grantee's current certificate, calls `buildRecoveryEnvelope(newPrivateKeyPem, granteeCert)`, and commits the fresh envelope to a migration endpoint that sets `recovery_envelope` and re-points `grantor_suite_id` to the new suite, leaving `state = granted`.

This reuses the loop, the per-record commit shape, and the server-side owner/suite scoping the other stores already have. The job differs only in its producer: attachment grants decrypt the old wrapped key and re-wrap it; emergency contacts ignore the old envelope entirely and build a new one from the new private key. Both end at "a row that used to point at the old suite now points at the new one, with material only the owner could have produced".

### D2: Best-effort migrate, listener sweeps the residual — no completion-gate change

The five migrated stores gate completion: the run cannot finalise while any of their rows remains on the old suite. Emergency contacts are deliberately **not** added to that gate.

The reason is that a contact can be legitimately un-migratable — the grantee left the instance, or revoked their suite, so there is no certificate to seal to. Gating completion on such a row would trap the vault exactly the way the *A Migration Always Has A Way To Terminate* requirement forbids. So emergency contacts stay outside the gate: the loop migrates every reachable one, and `invalidateForGrantorRotation()` runs at completion as it does today — but now finds only the residual, because the migrated contacts already left the old suite. The listener keeps its current code; its meaning narrows from "invalidate all" to "invalidate whatever the loop could not carry".

This is the least invasive correct design: no new column, no `migration_error` analogue for contacts, no change to the gate or its progress denominator. The trade-off is that a re-envelope that fails transiently (grantee cert briefly unfetchable) is swept into the residual rather than retried to exhaustion — acceptable, because the residual is re-designatable and the failure mode is "prompt to re-establish", never data loss.

### D3: The completion summary carries the residual, and the form acts on it

Today the loss is silent. With this change the completion response reports which contacts were invalidated rather than migrated, and `CompromiseRecoveryForm.vue` prompts the owner to re-designate exactly those. A rotation with all grantees reachable prompts nothing; a rotation with an unreachable grantee explains which one and why.

### D4: Bind to the grantee's current certificate, and let that be a feature

The new envelope seals to whatever certificate `getGranteeCertificate()` returns now, which may differ from the one the old envelope used if the grantee has since rotated. This is correct: an envelope sealed to a grantee's stale key would be unopenable by that grantee anyway. Re-enveloping on the grantor's rotation therefore also repairs staleness introduced by the grantee's own rotation, for free.

## Risks / Trade-offs

- **A grantee reachable at migration time but not later.** No worse than today: the envelope is valid when built, and any later grantee-side change is handled by the existing grantee-revocation invalidation. Not this change's concern
- **Transient cert-fetch failure demotes a contact to residual.** The owner is prompted to re-designate one contact they did not need to; a nuisance, not a loss. If it proves common, D2 could gain a bounded retry without changing the model
- **Two rotations in quick succession.** The first migrates the envelope to suite B; the second (B→C) re-reads contacts bound to B and migrates again. The work list is derived from `grantor_suite_id`, so this composes without special handling
- **The listener now means something narrower than its name.** `invalidateForGrantorRotation` will mostly invalidate nothing. Worth a comment at the call site so a future reader does not "fix" the apparent no-op

## Migration Plan

No data migration. Existing contacts keep working; the first rotation after this ships migrates their envelopes instead of dropping them. A rotation already in progress when this deploys completes under the old behaviour (invalidate) — acceptable, and the owner is prompted to re-designate, which is the pre-change status quo.

## Open Questions

- **Gate or sweep?** D2 chooses sweep (no completion-gate change). The alternative — make emergency contacts a gated store with an explicit "invalidate this one" acknowledgement, like the per-record failure path for secrets — is more uniform but needs a contact-level accounting field and touches the gate. Recommendation: ship the sweep; revisit only if the residual needs auditing beyond a re-designation prompt
- **Where does the client read the contacts to process?** DECIDED: the filtered read — the client reads the existing emergency-access index and selects `grantorSuiteId === oldSuiteId`, rather than widening `getWork`. This keeps `getWork`'s `totalRemaining` and the completion gate entirely untouched, which matters because emergency contacts are deliberately not gated (D2). Extending `getWork` was the uniform-looking alternative but would have put a non-gating list inside the endpoint whose whole output feeds the gate.
- **Attachments-style verification?** DECIDED: a shape check, not a round-trip. The other stores verify by decrypting what they just wrote, but an emergency envelope can only be opened by the grantee, so the grantor cannot round-trip it. The server therefore asserts the envelope parses, carries the expected `v`/`alg`, and declares a `granteeSuiteId` matching the grantee's current active suite. This catches a malformed or misaddressed envelope; it cannot catch a well-formed envelope sealed to the wrong plaintext, which is inherent to the trust model and no worse than initial designation, which has the same limit.
