## 1. Fix

- [x] 1.1 `EncryptionSuiteController::validateOwnership()` refuses unless `ownerType === 'user' && ownerId === $userId`; message "Access denied: suite belongs to another owner". Comment records why the previous `=== 'user' && ownerId !==` form failed open for application suites, and points at `CertificateLifecycleService::reissueSuite` as the already-correct sibling
- [x] 1.2 Confirmed no other caller relied on the old behaviour: the only callers are `show` (L111), `updatePrivateKey` (L236), `revoke` (L288) — all user self-service; the frontend calls `revoke` only on the caller's own current suite

## 2. Tests

- [x] 2.1 `testRevokeRefusesAnApplicationSuiteAndNeverCallsTheService` — a non-admin revoking an application suite gets `403 Access denied` and `revokeSuite` is never called
- [x] 2.2 `testUpdatePrivateKeyRefusesAnApplicationSuite` — the same guard blocks the envelope overwrite; `updateSuite` is never called
- [x] 2.3 Both proven to FAIL against the old guard and PASS against the fix; existing `testRevokeRefusesAnotherUsersSuite…` and the happy-path cases still pass (full class: 22 tests green)

## 3. Gates and Submission

- [x] 3.2 phpmd clean on the touched files; `validateOwnership` introduces no finding. phpcs/phpstan carry the file's pre-existing, contradictory named-argument debt (phpcs *requires* named params for internal calls; phpstan's `@no-named-arguments` *forbids* them on PHPUnit asserts) — present identically on `development`; the new lines follow the file's established style and add no meaningful new violation. Not fixing the baseline here (unrelated scope)
- [x] 3.4 PR #676 discloses AI tool use; the public-disclosure path was chosen deliberately by the contributor (consistent with the public #395 filing), so a public PR rather than HackerOne is the contributor's call on record
- [ ] 3.1 hydra gates — run in CI on PR #676, not locally (Hydra infra, not a repo composer script). no-admin-idor is the gate this fix *reduces* surface for; route-auth, gate-16 spec-coverage (new requirement backs the change), gate-113 exclusion-evidence apply
- [ ] 3.3 DCO — the contributor adds `Signed-off-by` at merge; the commits already carry `Assisted-by: ClaudeCode:claude-opus-5` and no sign-off, since only the human certifies the DCO
- [ ] 3.5 Independent human verification of the vulnerability and the fix — the contributor's review/merge is that verification; the empirical reproduction in this session is the agent's reading, not a substitute

_The three unchecked items are the merge-time human/CI actions: CI runs the gates (3.1), and the contributor's sign-off (3.3) and review-at-merge (3.5) are completed by merging. PR #676 is set to `Closes #675`, so the merge that carries the sign-off also closes this issue._
