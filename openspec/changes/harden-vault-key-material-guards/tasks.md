## 0. Read First — Ordering Constraint

The guard is a breaking change to four routes the shipped frontend already calls. Sections 1–3 are inert (nothing opts in yet). **Section 5 must not land before section 4**, or the frontend breaks against its own backend.

No database migration: `SuiteMigration::$status` is a plain `string` column (`lib/Db/SuiteMigration.php:115`), so the new `aborted` value needs no schema change and no `<version>` bump — gate-110 does not apply to this change. If that assumption changes, revisit before merging.

Section 3 (abort) is independently useful and can be split into its own PR if the whole change grows too large for one review — it has no dependency on sections 1, 2, 4 or 5.

## 1. Backend — The Guard Primitive

- [ ] 1.1 Create `lib/Attribute/VaultKeyProofRequired.php`: `#[Attribute(Attribute::TARGET_METHOD)]`, constructor `array $binds = []`, `string $subject = 'active'`; SPDX header per `contribute/HowToApplyALicense.md`
- [ ] 1.2 Create `lib/Service/VaultKeyProofService.php` with `issueChallenge(string $userId, string $purpose): array` returning `{nonce, expiresAt}` — nonce is `base64(ISecureRandom bytes) . '.' . HMAC(instance secret, random|uid|purpose|exp)`; no storage
- [ ] 1.3 Implement `VaultKeyProofService::verify(string $nonce, string $signature, string $publicKeyPem, string $userId, string $purpose, array $boundValues): void` — validate the HMAC, validate the expiry, rebuild the payload as `nonce || sha256(v1) || … || sha256(vn)` in declared order, verify with `openssl_verify` against the stored public key; throw a typed exception on every failure path with no distinction leaked to the caller
- [ ] 1.4 Do NOT use `ICacheFactory` for challenge state (design D5 — a null cache on a default install would make the guarded flows unusable). Assert this in review
- [ ] 1.5 Create `lib/Middleware/VaultKeyProofMiddleware.php` following `JwtAuthMiddleware`: `beforeController` reads the attribute via `new ReflectionMethod($controller, $methodName)`, resolves the subject suite (`'active'` → the session user's active suite via `EncryptionSuiteService::getActiveSuite`; `'routeParam:<name>'` → `IRequest::getParam`), collects the bound values via `IRequest::getParam`, reads `X-Keepiq-Key-Proof`, and delegates to the service
- [ ] 1.6 Implement `afterException` returning `403` with `['error' => 'key_proof_required', 'message' => …]`; re-throw anything that is not the guard's own exception, as `JwtAuthMiddleware` does
- [ ] 1.7 Middleware MUST NOT consult `IUserSession` backends, token scopes or `IPasswordConfirmationBackend` — no SSO/app-password carve-out (spec: *the guard is not waived*). Add an explanatory comment citing the NC `PasswordConfirmationMiddleware` bypasses this deliberately does not copy
- [ ] 1.8 Register in `lib/AppInfo/PlatformIntegrationRegistrar.php` alongside `JwtAuthMiddleware::class`
- [ ] 1.9 Run phpcs/phpstan/phpmd — watch `CouplingBetweenObjects` on the middleware; keep crypto in the service, which is also what makes it unit-testable

## 2. Backend — Challenge Endpoint

- [ ] 2.1 Add `proofChallenge(string $id)` to `EncryptionSuiteController` (`#[NoAdminRequired]`), returning `{nonce, expiresAt}` for the calling user and the requested purpose; validate suite ownership
- [ ] 2.2 Accept the purpose as a request parameter constrained to a known set (one per guarded operation); reject an unknown purpose
- [ ] 2.3 Register `['name' => 'encryptionSuite#proofChallenge', 'url' => '/api/v1/suites/{id}/proof-challenge', 'verb' => 'GET']` in `appinfo/routes.php`, before the SPA catch-all wildcard
- [ ] 2.4 The challenge endpoint itself MUST NOT carry `#[VaultKeyProofRequired]` — assert in the coverage test that it is on the deliberate-exclusion list

## 3. Backend — Abort (independently mergeable)

- [ ] 3.1 Add `MigrationService::abortMigration(string $migrationId): array` — refuse unless `in_progress`; refuse when any record has been committed to the new suite, reporting the count and pointing at resume; idempotent by status like `completeMigration`
- [ ] 3.2 On success: set status `aborted`, leave the old suite `active` and its records untouched, revoke the successor suite via `EncryptionSuiteService::revokeSuite`, clear failure accounting via `workService->clearFailureAccounting`, release the write lock
- [ ] 3.3 Create `SuiteMigrationAbortedEvent` and a listener that unlocks the SecretRequests locked by `SuiteMigrationStartedListener`
- [ ] 3.4 **Must not** dispatch `SuiteMigrationCompletedEvent` — that is what `EmergencyAccessSuiteRotationListener` consumes to invalidate recovery envelopes. Add a regression test asserting envelopes survive an abort
- [ ] 3.5 Add `MigrationController::abort(string $id)` (`#[NoAdminRequired]`) with the existing `requireOwnMigration` ownership check; **no** `#[VaultKeyProofRequired]` (design D6 — abort is restorative)
- [ ] 3.6 Register `['name' => 'migration#abort', 'url' => '/api/v1/migrations/{id}/abort', 'verb' => 'POST']`
- [ ] 3.7 Update the refusal text in `EncryptionSuiteController::compromiseRecovery()` so the promised "abort" now names a route that exists
- [ ] 3.8 Add an abort control to `src/components/MigrationResumeBanner.vue`, shown only while abort is still available, with copy stating that the old suite stays intact

## 4. Frontend — Producing the Proof

- [ ] 4.1 Add `proveMasterPassword(encryptedPrivateKey, masterPassword, nonce, boundValues)` to `src/crypto/reauth.js`: decrypt the envelope via `decryptPrivateKey` (`src/crypto/aes.js:79`), re-import the PKCS#8 bytes with `['sign']` usage, sign `nonce || sha256(v1) || …`, return the signature
- [ ] 4.2 Discard the derived AES key, the raw PKCS#8 bytes and the signing key immediately after signing; never return, store or cache them (spec: *the signing key does not outlive the proof*). Keep `verifyMasterPassword` as-is for the three existing advisory call sites — they are out of scope for this change
- [ ] 4.3 Confirm the signing key is imported with `['sign']` only and is NOT the session `CryptoKey`; add a unit test asserting the session key (`src/crypto/rsa.js:61-66`) remains non-extractable and `['decrypt']`-only
- [ ] 4.4 Add a shared client helper that fetches a challenge, prompts for the master password, produces the proof, and sets the `X-Keepiq-Key-Proof` header — so the four call sites do not each re-implement it
- [ ] 4.5 Wire `src/components/CompromiseRecoveryForm.vue` (recovery start, and the completion call) through the helper
- [ ] 4.6 Wire the routine master-password change flow through the helper; verify the old private key is materialised at that point (design "Risks" — if it is not, stop and raise before proceeding)
- [ ] 4.7 Wire the emergency-contact delete action in `src/views/EmergencyAccessView.vue` / `src/store/modules/emergencyAccess.js` through the helper
- [ ] 4.8 Handle `403 key_proof_required` as "re-enter your master password and retry", not as a terminal error

## 5. Apply The Guard (must not precede section 4)

- [ ] 5.1 `EncryptionSuiteController::compromiseRecovery` → `#[VaultKeyProofRequired(binds: ['publicKey', 'encryptedPrivateKey'])]`
- [ ] 5.2 `EncryptionSuiteController::updatePrivateKey` → `#[VaultKeyProofRequired(binds: ['encryptedPrivateKey'], subject: 'routeParam:id')]`
- [ ] 5.3 `MigrationController::complete` → `#[VaultKeyProofRequired]` (defence in depth; the acknowledgement stays — they answer different questions)
- [ ] 5.4 `EmergencyAccessController::destroy` → `#[VaultKeyProofRequired]`
- [ ] 5.5 Create `tests/Unit/Controller/VaultKeyProofAttributesTest.php` in the shape of `RateLimitAttributesTest`: a provider enumerating the four methods with their expected `binds` and `subject`, asserting each by reflection; plus a deliberate-exclusion list (abort, proof-challenge) with the reason recorded per entry

## 6. Tests

- [ ] 6.1 `tests/Unit/Service/VaultKeyProofServiceTest.php`: valid proof passes; wrong key fails; altered bound value fails; altered nonce fails; expired nonce fails (injected `ITimeFactory`); wrong purpose fails; proof for one parameter set rejected against another
- [ ] 6.2 `tests/Unit/Middleware/VaultKeyProofMiddlewareTest.php`: attribute absent → pass-through; attribute present without header → 403 `key_proof_required`; `subject: 'active'` and `'routeParam:id'` both resolve; `afterException` re-throws foreign exceptions
- [ ] 6.3 Cross-implementation round-trip: sign with WebCrypto in a JS test, verify with `openssl_verify` in PHPUnit (config rule: *test cross-implementation encryption round-trips*)
- [ ] 6.4 `MigrationServiceTest`: abort on an untouched migration; abort refused after a commit with the count reported; abort idempotent by status; emergency-access envelopes unchanged after abort; completed event NOT dispatched
- [ ] 6.5 `EncryptionSuiteControllerTest` / `MigrationControllerTest` / `EmergencyAccessControllerTest`: the four guarded routes refuse without a proof and proceed with one
- [ ] 6.6 Regression test for the bypass in proposal finding 2: committing ciphertext for every record and completing MUST now be refused at the recovery entry point without a proof
- [ ] 6.7 Frontend unit tests for `proveMasterPassword` (signature verifies against the suite public key; keys discarded) and for the 403 retry path

## 7. Gates and Documentation

- [ ] 7.1 Run the hydra gates locally: route-auth (two new routes), no-admin-idor, gate-16 spec-coverage, gate-113 exclusion-evidence (every `@e2e exclude` in this change carries a reason). Note the known pre-existing `no-admin-idor` debt on `development` is not introduced here
- [ ] 7.2 Confirm gate-110 does not apply (no migration added) — if a migration is introduced after all, bump `appinfo/info.xml` `<version>` from `0.3.1`
- [ ] 7.3 Document the guard in `docs/ARCHITECTURE.md`: the attribute contract, the sign-not-decrypt rationale (design D2), and the rule that a new destructive route must be added to `VaultKeyProofAttributesTest`
- [ ] 7.4 Every commit carries `Assisted-by: ClaudeCode:claude-opus-5`. Do NOT add `Signed-off-by` — only the human contributor certifies the DCO
- [ ] 7.5 The PR description discloses AI tool use, in the contributor's own words, and links issue #395
- [ ] 7.6 Before opening: re-read #395's "Verification status" — the chain was never executed end to end. Reproduce the lockout on a throwaway account against pre-fix code, then confirm the same steps are refused post-fix. This is the issue's own first task and it is still outstanding
