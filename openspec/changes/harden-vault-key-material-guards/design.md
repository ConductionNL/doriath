# Design — harden-vault-key-material-guards

## Context

Under ADR-003 (always-E2E) the server holds ciphertext and an AES-wrapped private key, never the master password. That makes *reading* the vault cryptographically gated. It leaves *writing* gated only by the Nextcloud session, because writing a secret needs nothing but the owner's public key — which is by design.

Issue #395 shows what that costs on the paths that write **key material** rather than secrets. `EncryptionSuiteController::compromiseRecovery()` accepts an attacker's own keypair and proves nothing about the suite being replaced; `updatePrivateKey()` overwrites the envelope in place behind an ownership check; `MigrationController::complete()` marks the old suite `compromised`; `EmergencyAccessController::destroy()` deletes the recovery envelope. None can be undone: `EncryptionSuiteService::reinstateSuite()` accepts `revoked` and refuses `compromised`, and there is no abort route.

Keepiq already has both halves of the mechanism this needs, unconnected:

- **Server**: `lib/Middleware/JwtAuthMiddleware.php` + `PlatformIntegrationRegistrar.php:64` establish the app-middleware pattern (`beforeController` throws, `afterException` renders JSON). `tests/Unit/Controller/RateLimitAttributesTest.php` establishes attribute-coverage testing as a build guard.
- **Client**: `src/crypto/reauth.js` derives the AES key from a freshly entered master password, decrypts the private-key envelope to prove knowledge, and discards every derived key immediately. Its own header states that the control is *advisory* because only the client sees the result.

This change connects them.

## Goals / Non-Goals

**Goals**

- No irreversible operation on vault contents or key material succeeds without a **server-verified** proof of master-password knowledge
- The guard is declarative and reusable: a future destructive route opts in with one attribute, and forgetting the attribute fails the build
- Every wedged migration has a route back to a working vault (abort)
- No new runtime dependency, no new table, no new cache requirement

**Non-Goals**

- Gating *every* write on the master password. See D3 — a blanket rule would be strictly less safe than a targeted one
- Recovering a vault whose owner has genuinely forgotten the master password. Rotation exists for a key that may be *exposed*, not for a password that was *forgotten*; the lost-password route is administrator revocation and is deliberately deferred to a follow-up change
- Defending against a client that keylogs the master-password field. No client-side-rooted E2E system can, and `reauth.js` already says so
- Retrofitting the three existing advisory `verifyMasterPassword()` gates. Follow-up change

## Decisions

### D1: The proof is a signature over a server-issued challenge, verified against the stored public key

`GET /api/v1/suites/{id}/proof-challenge` returns a nonce. The client decrypts the private-key envelope with the freshly entered master password, re-imports the PKCS#8 bytes with `['sign']` usage, signs, discards the key, and sends the signature in `X-Keepiq-Key-Proof`. The middleware verifies it against the suite's stored public key.

The server can do this because it already holds the public key and the certificate. It cannot verify anything about the *plaintext* — and does not need to. Possession of the private key implies possession of the master password, because the private key exists only inside an AES envelope keyed by PBKDF2-SHA256 over that password.

This is what closes finding 2 in the proposal. Gating `complete()` alone is insufficient because an attacker can commit garbage ciphertext and complete with zero failures; gating the *entry* to rotation stops every downstream variant, including that one. `complete()` is still guarded, as defence in depth, but it is not where the fix lives.

### D2: Signature, never decryption — this is load-bearing

The obvious alternative is a decrypt challenge: the server encrypts a nonce to the suite public key and the client returns the plaintext. **This must not be used.** The session `CryptoKey` (`src/crypto/rsa.js:61-66`) is imported:

```js
crypto.subtle.importKey('pkcs8', keyData,
    { name: 'RSA-OAEP', hash: 'SHA-256' },
    false,        // extractable = false — security critical
    ['decrypt'],  // decrypt only
)
```

Non-extractable, and decrypt-only. So:

| challenge design | satisfiable by an unlocked tab | satisfiable by XSS in that tab |
|---|---|---|
| "decrypt this nonce" | yes | yes |
| "sign this nonce" | no | no |

A decrypt challenge is satisfiable by the long-lived session key, which means XSS in an unlocked tab defeats it. Signing requires re-importing the raw PKCS#8 bytes with `['sign']` usage, and those bytes exist only for the instant `decryptPrivateKey()` (`src/crypto/aes.js:79`) returns them — which requires the password. `extractable: false` is precisely what makes the proof unforgeable from a live session, and it only pays off if the proof is a signature.

Anyone tempted to simplify this later should read this decision first.

### D3: The guard is a step-up gate on irreversible operations, not a blanket write gate

Producing a signature requires the raw private key. Gating every write therefore means either a password prompt plus ~1s of PBKDF2 (600k rounds) on every secret created, or holding a signing-capable key in memory for the session.

The second undoes `extractable: false` and hands XSS the exact capability the guard exists to deny. A blanket rule would make the app **less** safe than a targeted one. The enforceable invariant is therefore:

> No irreversible operation on vault contents or key material without a server-verified proof of the master password — produced at the moment the user enters it, and discarded immediately.

Reading is already cryptographically gated and needs nothing added. Creating a secret needs only the public key and stays ungated.

### D4: The attribute carries the binding, so the middleware stays route-agnostic

A proof that authorises "some operation" is replayable onto a different operation. Binding the signature to the request is what prevents that — but the middleware cannot see the request body. `Request::decodeContent()` reads `php://input` via `file_get_contents`, `json_decode`s it and **discards the raw string**; `getContent()` is `protected`; and `IRequest`'s entire public surface is `getHeader / getParam / getParams / ...` with no raw-body accessor. Re-reading `php://input` from app code would bypass the injectable `inputStream` the Request is constructed with, making the guard the one part untestable in an isolated PHPUnit run.

So the attribute declares the binding and the middleware reads named parameters:

```php
#[VaultKeyProofRequired(binds: ['publicKey', 'encryptedPrivateKey'])]
public function compromiseRecovery(string $publicKey, string $encryptedPrivateKey): JSONResponse

#[VaultKeyProofRequired(binds: ['encryptedPrivateKey'], subject: 'routeParam:id')]
public function updatePrivateKey(string $id, string $encryptedPrivateKey): JSONResponse
```

- `binds` — request parameters the proof commits to, hashed individually in declared order
- `subject` — whose public key verifies: `'active'` (the session user's active suite, default) or `'routeParam:<name>'`

Signed payload: `nonce || sha256(param_1) || ... || sha256(param_n)`.

Three things fall out. There is no canonicalisation problem — only named scalar parameters, hashed individually, so `crypto.subtle` and PHP never have to agree on JSON key ordering, number formatting or unicode normalisation. The binding is legible at the route rather than buried in the middleware. And the proof travels as a header, so no guarded controller signature grows a `?string $proof` it never reads.

### D5: The nonce is stateless, because the binding makes single-use unnecessary

`nonce = base64(random) . '.' . HMAC(instance secret, random | uid | purpose | exp)`. The middleware verifies the HMAC and the expiry; no storage, no table.

Replay is not a gap here. Because the signature commits to the operation's parameters, a captured proof only ever re-authorises the byte-identical operation: for `compromiseRecovery` that is the victim's own successor key, for `updatePrivateKey` it is re-setting the envelope already in place. `purpose` binds the challenge to one route, so a proof for one guarded operation cannot be presented to another.

Deliberately **not** `ICacheFactory`: without a configured distributed cache Nextcloud returns a null cache, and a nonce store that silently forgets would break the flow on a default install.

### D6: Abort terminates a migration only while nothing has been committed

The reachable states, given a migration A -> B:

```
   A ──────────────▶ B         n of m records already re-encrypted to B
        migration

   abort + revoke B      ⇒ those n records unreadable            ✗
   abort + keep B active ⇒ the other m-n stranded on A           ✗
   abort only while n = 0                                        ✓
```

The third rule is both defensible and sufficient: an attacker commits nothing, because producing valid re-encrypted ciphertext requires the plaintext and therefore the master password. Once any record has been committed the remedy is resume, not abort, and the refusal names the count.

Abort sets `aborted`, clears failure accounting, revokes the unused successor suite, leaves the old suite `active`, releases the write lock, and dispatches a new `SuiteMigrationAbortedEvent` so `SuiteMigrationStartedListener`'s locked SecretRequests are released. It **must not** dispatch `SuiteMigrationCompletedEvent` — that is what `EmergencyAccessSuiteRotationListener` consumes to invalidate the recovery envelopes, and abort exists to avoid exactly that loss.

Abort carries **no** `#[VaultKeyProofRequired]`, deliberately. It is restorative: it returns the vault to the old suite, still `active`. An attacker aborting a victim's legitimate rotation is a nuisance the victim can simply redo, whereas a proof requirement on abort would leave a wedged vault wedged.

### D7: Coverage is guarded by a test, because attribute guards fail open by omission

The failure mode of every declarative guard is the route that forgets it: nothing errors, the guard is simply absent. Notably, NC's own `PasswordConfirmationMiddleware` shows the same shape from the inside — `canConfirmPassword()`, the `SCOPE_SKIP_PASSWORD_VALIDATION` token scope and an `excludedUserBackEnds` list for SAML each `return;` and the guard disappears rather than failing.

`RateLimitAttributesTest` already solves this locally for `#[AnonRateLimit]`: enumerate the routes that must carry an attribute, assert by reflection that each does. `VaultKeyProofAttributesTest` does the same for the destructive list, so a new destructive route without the guard turns the build red.

Our guard has no equivalent bypass to make: it never consults the auth backend, so it behaves identically on SSO, app-password and ordinary sessions.

### D8: Verification lives in a service, not in the middleware

`VaultKeyProofService` owns challenge issuance and signature verification; the middleware owns attribute dispatch, subject resolution, parameter collection and the 403. This keeps the crypto unit-testable without the app framework, and mirrors how `JwtAuthMiddleware` delegates to `JwtAuthService`.

The 403 body carries `error: 'key_proof_required'` so a client can tell "fetch a challenge and retry" from a dead end, the same way `migration_incomplete` and `migration_in_progress` are already distinguishable.

## Risks / Trade-offs

- **`updatePrivateKey` is the hot path.** It is the routine master-password change, so the guard lands on a flow users hit regularly. Mitigated by the fact that the flow already holds the old password in order to re-wrap the envelope — the proof is free at that moment. If the flow is ever changed to derive the new envelope without materialising the old key, the guard breaks; the spec scenario pins this
- **Breaking API change on four routes.** Deliberate, and cheap only because the app is pre-production. Any out-of-tree client of those routes must be updated
- **A user who has forgotten the master password can no longer rotate.** This is the correct behaviour, not a regression — but it means the lost-password route (administrator revocation, with the emergency-access warnings from #395) is now load-bearing and must not be deferred indefinitely
- **PBKDF2 cost on the guarded flows.** ~1s per proof at 600k rounds. Acceptable on operations a user performs a handful of times; unacceptable per-write, which is D3
- **Proof of possession is not proof of intent.** A user tricked into typing their master password into a hostile flow still produces a valid proof. The guard raises the bar from "a cookie" to "the password", which is the stated goal, and no further

## Migration Plan

No data migration. `aborted` is a new value in a plain `string` status column, so no schema change and no `<version>` bump.

Ordering matters for the rollout, because the guard is a breaking change to routes the shipped frontend calls:

1. Attribute, service, middleware, challenge endpoint, registration — inert until a route opts in
2. `abort` route and its event — independently useful, unblocks any already-wedged migration
3. Client `proveMasterPassword()` and the four call sites
4. Apply `#[VaultKeyProofRequired]` to the four routes, plus `VaultKeyProofAttributesTest`

Steps 3 and 4 must land together, or in that order, or the frontend breaks against its own backend. Migrations already `in_progress` when this deploys are unaffected: the guard applies to starting a rotation and to completing one, and `abort` gives any migration wedged by a pre-fix attempt a way out.

## Open Questions

- Should `complete()` keep the `acceptUnrecoverable` acknowledgement now that a key proof is required? It no longer carries the security weight (finding 2), but it is still the mechanism that makes losing a record a decision the owner made rather than a side-effect. Recommendation: keep both; they answer different questions
- The lost-password route (administrator revocation as the only way back to a working vault once this guard blocks a forgotten-password rotation) is out of scope here, and is now partly in place around it: a plain create after revocation already works via #392, and the destruction warning plus the refuse-while-a-usable-emergency-contact-exists enforcement have been folded into #674 (`migrate-emergency-access-on-rotation`). What remains genuinely open is only whether any further UI is needed to walk a forgotten-password user through revoke -> recreate; the destructive mechanics are covered
- ~~`#395` also observes that **any** completed rotation costs the user their emergency access, since `invalidateForGrantorRotation()` fires on `SuiteMigrationCompletedEvent`.~~ **RESOLVED by #674** (`migrate-emergency-access-on-rotation`): rotation now re-envelopes each reachable emergency contact under the new key instead of dropping it, and only a contact whose grantee is unreachable is invalidated — with the owner prompted to re-designate that one specifically. The silent break-glass loss after a routine key change is gone
