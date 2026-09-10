## 0. Read First — Scope and Ordering

Scope is **compromise-recovery rotation only**. The routine master-password change keeps the same RSA key pair, so escrowed private keys stay valid and this change does not touch that flow (`changePassword` / `updatePrivateKey`).

No database migration: re-enveloping reuses the existing `recovery_envelope` and `grantor_suite_id` columns of `keepiq_emergency_contacts`. No schema change, no `<version>` bump — gate-110 does not apply. If that assumption changes, revisit.

Composes with `harden-vault-key-material-guards` but does not depend on it. Landing this resolves that change's third open question (rotation silently costing emergency access).

Design fork still open (see design.md): the client may read the contacts to migrate either from an extended `getWork` or from the existing emergency-access index filtered by `grantorSuiteId`. Tasks below assume the **filtered read** (smaller blast radius on the completion gate); if `getWork` is chosen instead, 1.1 and 2.2 move accordingly.

## 1. Backend — Re-point Endpoint and Read

- [ ] 1.1 Add a read the client can use to enumerate the rotating owner's emergency contacts still bound to the old suite: reuse `EmergencyContactMapper::findByGrantorSuite($oldSuiteId)` filtered to the migration owner, returning `id`, `granteeUserId`, and `state` (exclude already-`invalidated`). Prefer the existing emergency-access index over widening `getWork`, so the completion gate and its progress denominator are untouched
- [ ] 1.2 Add a migration re-point endpoint (e.g. `POST /api/v1/migrations/{id}/emergency-contacts/{contactId}`) accepting a fresh `recoveryEnvelope`; it MUST set `recovery_envelope`, set `grantor_suite_id` to the migration's new suite, keep `state = granted`, and clear any `invalidated_reason`
- [ ] 1.3 Enforce scoping identically to the other migration writes: refuse unless the contact's current `grantor_suite_id` is the migration's `old_suite_id` and the contact's grantor is the migration owner (resolve the acting user via `OCP\IUserSession`)
- [ ] 1.4 Validate the submitted envelope's shape server-side as far as is possible without the grantee's key: it MUST parse, carry the expected `v`/`alg`, and its declared `granteeSuiteId` MUST match the grantee's current active suite (a shape check, not a round-trip — only the grantee can open it)
- [ ] 1.5 Register the route in `appinfo/routes.php` before the SPA catch-all wildcard
- [ ] 1.6 Add a comment at the `invalidateForGrantorRotation()` call site noting it is now a **residual sweep**: after the loop it finds only contacts the migration could not carry (grantee unreachable). Do not "optimise away" the apparent no-op

## 2. Frontend — Build and Commit the New Envelopes

- [ ] 2.1 In `initiateCompromiseRecovery` (`src/store/modules/encryptionSuite.js`), after the new key pair is generated and before/within the migration loop, fetch the owner's emergency contacts on the old suite (1.1)
- [ ] 2.2 For each contact: fetch the grantee's current certificate via `getGranteeCertificate(granteeUserId)`; on success call `buildRecoveryEnvelope(newPrivateKeyPem, granteeCert)` and POST it to the re-point endpoint (1.2). `newPrivateKeyPem` is already materialised in this function — reuse it, do not re-derive
- [ ] 2.3 On a grantee with no active certificate (fetch throws / returns none), do NOT commit: leave the contact bound to the old suite so the completion sweep invalidates it, and collect it into a `residualContacts` list
- [ ] 2.4 Treat a transient re-point failure as residual for this run (the contact is re-designatable); do not halt the migration on it — emergency contacts are outside the completion gate
- [ ] 2.5 The raw new private key PEM MUST stay in the existing rotation scope and MUST NOT be persisted or logged; only envelope ciphertext crosses the wire (ADR-003)

## 3. Frontend — Surface the Residual

- [ ] 3.1 Include `residualContacts` (grantee display names) in the migration outcome returned by `initiateCompromiseRecovery`
- [ ] 3.2 In `CompromiseRecoveryForm.vue`, on completion, prompt the owner to re-establish exactly the residual contacts; show nothing about emergency access when every contact migrated
- [ ] 3.3 Use `@conduction/nextcloud-vue` components and the NL Design System double-fallback CSS pattern, consistent with the rest of the form

## 4. Tests

- [ ] 4.1 Unit test the re-point endpoint: re-points `grantor_suite_id` to the new suite, keeps `state = granted`, clears `invalidated_reason`; refuses when the contact is on a different suite or owned by another user; rejects a malformed envelope and a `granteeSuiteId` that does not match the grantee's current suite
- [ ] 4.2 Unit test the residual sweep: after the loop, `invalidateForGrantorRotation(oldSuiteId)` invalidates only contacts still on the old suite; a migrated contact (now on the new suite) is untouched
- [ ] 4.3 Frontend unit test: a reachable grantee yields a `buildRecoveryEnvelope(newPrivateKeyPem, cert)` call and a commit; an unreachable grantee yields no commit and a residual entry
- [ ] 4.4 Cross-implementation sanity: an envelope built in JS parses under the server's shape check (config rule: test cross-implementation round-trips as far as the trust model allows)
- [ ] 4.5 Regression: a rotation with all grantees reachable prompts no re-designation and leaves no contact invalidated (the behaviour this change fixes)
- [ ] 4.6 Two-rotations-in-succession: a contact migrated A→B is then migrated B→C, found each time via `grantor_suite_id`

## 5. Gates and Documentation

- [ ] 5.1 Run the hydra gates locally: route-auth (one new route), no-admin-idor (the re-point endpoint is owner-scoped by construction), gate-16 spec-coverage, gate-113 exclusion-evidence (every `@e2e exclude` carries a reason)
- [ ] 5.2 Confirm gate-110 does not apply (no migration). If a schema change is introduced after all, bump `appinfo/info.xml` `<version>` from `0.3.1`
- [ ] 5.3 Update `docs/ARCHITECTURE.md` where it describes suite migration: emergency contacts are a migrated store, and `invalidateForGrantorRotation` is a residual sweep
- [ ] 5.4 Every commit carries `Assisted-by: ClaudeCode:claude-opus-5`; no `Signed-off-by` (only the human certifies the DCO)
- [ ] 5.5 PR description discloses AI tool use in the contributor's own words and links the `harden-vault-key-material-guards` change whose open question this resolves
