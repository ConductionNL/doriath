## ADDED Requirements

### Requirement: Irreversible Operations Require A Verified Key Proof

The system MUST refuse any operation that can render vault contents or key material permanently unreadable unless the request carries a **key proof**: a signature, made with the private key of the owner's EncryptionSuite, over a challenge the server issued.

The server MUST verify the signature against the public key it already stores for the subject suite. Because a suite's private key exists only inside an AES envelope keyed by PBKDF2-SHA256 over the master password, a verified proof establishes that the caller knows the master password. A Nextcloud session alone MUST NOT be sufficient authority for any such operation.

The guard MUST be declared on the controller method via a `#[VaultKeyProofRequired]` attribute and enforced by middleware, so that the requirement is legible at the route and cannot be satisfied by controller code that forgets to call it.

A request missing or failing the proof MUST be refused with `403` and a machine-readable `error` of `key_proof_required`, so a client can distinguish "obtain a challenge and retry" from a terminal failure.

The guard MUST NOT consult the authentication backend, and MUST NOT be waived for SSO sessions, app passwords, or any token scope. Its authority derives from key material, not from how the session was established.

#### Scenario: A session without a proof is refused

@e2e exclude Middleware dispatch and signature verification are server-side; a DOM flow cannot present a request with the proof header withheld. Covered by PHPUnit on the middleware and service.
- **GIVEN** an authenticated session for a user who owns an active EncryptionSuite
- **WHEN** a guarded operation is requested without a key proof
- **THEN** the system MUST refuse with `403` and `error: key_proof_required`
- **AND** MUST NOT perform any part of the operation

#### Scenario: A valid proof admits the operation

@e2e exclude Requires signing with raw private-key bytes held only transiently in JS memory; not observable or triggerable via Playwright DOM. Covered by PHPUnit plus a cross-implementation round-trip test.
- **GIVEN** a challenge issued for the caller and the operation
- **AND** a signature over that challenge made with the subject suite's private key
- **WHEN** the guarded operation is requested carrying that proof
- **THEN** the system MUST verify the signature against the stored public key and proceed

#### Scenario: The guard is not waived for SSO or app-password sessions

@e2e exclude Requires provisioning an SSO or app-password session against a live instance. Covered by PHPUnit asserting the middleware reads no token scope and no user backend.
- **GIVEN** a session established by SSO, or authenticated with an app password
- **WHEN** a guarded operation is requested without a key proof
- **THEN** the system MUST refuse exactly as for an ordinary session

### Requirement: The Proof Is A Signature, Never A Decryption

The proof MUST be a signature produced with the subject suite's private key. The system MUST NOT accept, as proof, the decryption of a server-issued ciphertext.

The browser holds the unlocked session key as a WebCrypto `CryptoKey` imported non-extractable with `['decrypt']` usage only. A decryption challenge would therefore be satisfiable by any unlocked tab, and so by script injected into one, which would defeat the guard for the attacker it most needs to stop. Signing requires re-importing the private key with `['sign']` usage from raw PKCS#8 bytes, which are obtainable only by decrypting the envelope with a freshly entered master password.

The client MUST derive the signing key at the moment the master password is entered and MUST discard it immediately after signing. It MUST NOT retain a signing-capable key for the duration of the session, because doing so would grant injected script the capability this requirement exists to withhold.

#### Scenario: An unlocked session cannot produce a proof by itself

@e2e exclude The in-memory CryptoKey and its usage flags cannot be inspected via Playwright DOM. Covered by unit tests of the client crypto module asserting the session key is imported with `['decrypt']` only.
- **GIVEN** a vault unlocked in the browser, with the session `CryptoKey` in memory
- **WHEN** a key proof is required and the master password has not been re-entered
- **THEN** the client MUST be unable to produce a signature from the session key
- **AND** MUST prompt for the master password

#### Scenario: The signing key does not outlive the proof

@e2e exclude JavaScript memory lifetime is not observable via Playwright DOM. Covered by unit tests asserting the derived key is not returned, stored, or retained after signing.
- **GIVEN** the user has entered their master password to authorise a guarded operation
- **WHEN** the signature has been produced
- **THEN** the client MUST discard the derived AES key and the signing key
- **AND** MUST NOT place either in `localStorage`, `sessionStorage`, or a store that outlives the operation

### Requirement: A Proof Is Bound To The Operation It Authorises

A key proof MUST commit to the parameters of the operation it authorises, so that a captured proof cannot be replayed onto a different operation.

The `#[VaultKeyProofRequired]` attribute MUST declare which request parameters the proof binds to, and the signed payload MUST be the challenge followed by the digest of each declared parameter, hashed individually in the declared order. The attribute MUST also declare which suite's public key verifies the proof: by default the caller's active suite, or a suite named by a route parameter.

Binding MUST NOT be expressed as a digest over the whole request body. The framework decodes a JSON body and discards the raw bytes, so a whole-body digest would require re-reading the input stream outside the request abstraction, and would additionally require client and server to agree on a canonical serialisation.

A challenge MUST additionally be bound to a single purpose, so that a proof obtained for one guarded operation cannot be presented to another.

#### Scenario: A proof does not transfer to a different operation

@e2e exclude Server-side signature verification against a bound payload; not DOM-observable. Covered by PHPUnit on the middleware.
- **GIVEN** a valid proof issued and signed for one guarded operation
- **WHEN** it is presented to a different guarded operation
- **THEN** the system MUST refuse it

#### Scenario: A proof does not transfer to different parameters

@e2e exclude As above. Covered by PHPUnit on the middleware.
- **GIVEN** a valid proof bound to a set of request parameters
- **WHEN** the same proof is presented with any bound parameter altered
- **THEN** the system MUST refuse it

### Requirement: Challenges Are Stateless And Expiring

The system MUST issue key-proof challenges through an endpoint that requires only an authenticated session, and MUST NOT require server-side storage to verify them.

A challenge MUST carry a random component and MUST be authenticated with the instance secret over that component, the caller, the purpose, and an expiry. The system MUST reject an expired or unauthenticated challenge.

The system MUST NOT depend on a distributed cache to hold challenge state. Nextcloud returns a null cache when none is configured, and a challenge store that silently forgets would make the guarded flows unusable on a default installation.

Single-use enforcement is NOT required, because a proof is bound to its operation's parameters and therefore replays only ever re-authorise the byte-identical operation.

#### Scenario: An expired challenge is refused

@e2e exclude Time-dependent server-side verification; not DOM-observable. Covered by PHPUnit with an injected time factory.
- **GIVEN** a challenge whose expiry has passed
- **WHEN** a proof over it is presented
- **THEN** the system MUST refuse the request with `error: key_proof_required`

#### Scenario: A forged challenge is refused

@e2e exclude Server-side HMAC verification; not DOM-observable. Covered by PHPUnit.
- **GIVEN** a challenge not issued by this instance, or altered after issue
- **WHEN** a proof over it is presented
- **THEN** the system MUST refuse the request

### Requirement: Guard Coverage Is Enforced By Test

Because a declarative guard fails open when it is omitted, the system MUST carry a test that enumerates every operation required to be guarded and asserts, by reflection, that each carries `#[VaultKeyProofRequired]` with the expected binding and subject.

Adding a route that can render vault contents or key material permanently unreadable without adding it to that enumeration MUST be treated as a defect in this requirement, not as an accepted gap.

#### Scenario: A guarded route that loses its attribute fails the build

@e2e exclude Attribute reflection over controller methods; the middleware itself needs a running instance to produce a 403, which is out of scope for an isolated PHPUnit run — the same rationale documented for `RateLimitAttributesTest`.
- **GIVEN** the enumeration of operations required to carry a key proof
- **WHEN** any enumerated method does not carry `#[VaultKeyProofRequired]`, or carries it with an unexpected binding or subject
- **THEN** the test suite MUST fail
