## 1. Fix

- [x] 1.1 `EncryptionSuiteController::validateOwnership()` refuses unless `ownerType === 'user' && ownerId === $userId`; message "Access denied: suite belongs to another owner". Comment records why the previous `=== 'user' && ownerId !==` form failed open for application suites, and points at `CertificateLifecycleService::reissueSuite` as the already-correct sibling
- [ ] 1.2 Confirm no other caller relied on the old behaviour: `show`, `updatePrivateKey`, `revoke` are the only callers, all user self-service; the frontend calls `revoke` only on the caller's own current suite

## 2. Tests

- [x] 2.1 `testRevokeRefusesAnApplicationSuiteAndNeverCallsTheService` — a non-admin revoking an application suite gets `403 Access denied` and `revokeSuite` is never called
- [x] 2.2 `testUpdatePrivateKeyRefusesAnApplicationSuite` — the same guard blocks the envelope overwrite; `updateSuite` is never called
- [x] 2.3 Both proven to FAIL against the old guard and PASS against the fix; existing `testRevokeRefusesAnotherUsersSuite…` and the happy-path cases still pass (full class: 22 tests green)

## 3. Gates and Submission

- [ ] 3.1 Run the hydra gates locally: no-admin-idor (this is the fix for one), route-auth, gate-16 spec-coverage (the new requirement backs the code change), gate-113 exclusion-evidence (each `@e2e exclude` carries a reason)
- [ ] 3.2 phpcs/phpstan/phpmd clean on the touched files
- [ ] 3.3 Commit carries `Assisted-by: ClaudeCode:claude-opus-5`; no `Signed-off-by` (only the human certifies the DCO)
- [ ] 3.4 PR description discloses AI tool use in the contributor's own words. Because this is a security fix, follow the project security policy on disclosure: consider whether it should go through HackerOne rather than a public PR/issue before the app is production — this is the contributor's call, not the agent's
- [ ] 3.5 Independent human verification of the vulnerability and the fix before submission, per AGENTS.md — the empirical reproduction in this session is the agent's reading, not a substitute
