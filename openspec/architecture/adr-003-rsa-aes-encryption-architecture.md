# ADR-003: RSA + AES Encryption Architecture

**Status**: accepted (revised 2026-03-30 — always E2E, DecryptService for internal apps)

**Date**: 2026-03-23 (original), 2026-03-30 (revised)

## Context

Keepiq stores secrets (passwords, API keys, and other sensitive values) for multiple users and applications. The core security requirements are:

1. Secrets are encrypted at rest and can only be decrypted by the holder of the correct private key
2. A user or application can write a secret into another party's vault without being able to read it afterwards
3. Private keys are never stored in plaintext — they are always protected by a master password (users) or passphrase (applications)
4. The master password is never stored in the database
5. **The server never decrypts secrets with entity context** — decrypted plaintext must never be linkable to a Keepiq entity (secret, user, application) in server memory

Requirement 5 was added after evaluating the security model: Bitwarden, Passbolt, Proton Pass, and 1Password all decrypt client-side. For a product that positions itself as an encrypted vault, the server should never see plaintext secrets in context. This rules out the original server-side-only architecture.

## Decision

### Encryption scheme (unchanged from original)

Keepiq uses a hybrid RSA + AES encryption scheme:

**Secrets are encrypted with RSA (asymmetric):**
- Each user and application has an EncryptionSuite containing a public/private key pair
- Secrets are encrypted with the owner's public certificate — only the private key can decrypt them
- This enables write-without-read: any party can encrypt a secret for an owner without being able to read it back
- Minimum key size: 4096 bits (system default; only allowed to increase)

**Private keys are encrypted with AES (symmetric):**
- The private key in each EncryptionSuite is stored AES-256 encrypted
- For users: the AES key is derived from the master password (which is never stored)
- For applications: the AES key is derived from a passphrase (managed by the application)

**Certificate Authority:**
- A private CA is bootstrapped on first setup: one root certificate and one intermediate certificate
- User and application public certificates are signed by the intermediate
- The intermediate's private key is stored AES-encrypted in the database

**Chunking:**
- RSA encryption has a per-chunk limit of approximately 500 bytes (key size in bytes - 1 - 11 bytes PKCS#1 padding)
- Large values (additional fields, future file encryption) must be chunked before encryption
- Chunking logic must be implemented identically in both PHP (OpenSSL) and JavaScript (WebCrypto)

### Always E2E — no server-side decrypt path

All decryption happens at the client. There is no server-side decrypt mode, no admin toggle, and no `X-Vault-Password` header. The API always returns encrypted blobs.

```
GET /api/v1/secrets/{id}
→ { name: "GitHub", url: "github.com",
    key: "<base64 RSA blob>",
    login: "<base64 RSA blob>",
    additional_fields: "<base64 RSA blob>" }

Name and URL are always unencrypted (enables search).
Encrypted fields are always returned as blobs — the server never decrypts them.
```

### Decrypt location by client type

| Client | Auth | Decrypt | Private key storage |
|--------|------|---------|-------------------|
| Browser user | Nextcloud session | Client-side (WebCrypto) | JS `CryptoKey` in memory (non-exportable) |
| External application | JWT (RFC 7523) | Client-side (app's own runtime) | App manages externally |
| Internal Nextcloud app | PHP service call (no REST) | Server-side via `DecryptService` (stateless) | `ICredentialsManager` |
| Link share visitor | Link token | Client-side (WebCrypto) | Browser (Argon2id → AES key) |
| Secret request filler | Request token | Client-side (WebCrypto) | Browser (public cert for encrypt) |

### Browser users — WebCrypto

All encryption and decryption for browser users happens client-side using the WebCrypto API:

1. User enters master password on the lock screen — it never leaves the browser
2. Browser fetches the AES-encrypted private key blob from the API
3. Browser derives the AES key from the master password and decrypts the private key
4. The decrypted private key is imported as a WebCrypto `CryptoKey` with `extractable: false` — even XSS cannot exfiltrate the raw key material; it can only be used for decrypt operations
5. The `CryptoKey` is held in a JavaScript variable (not `localStorage` or `sessionStorage`)
6. Cleared on: tab close, session timeout, user locks vault

**Sharing in E2E:**
When Alice shares a secret with Bob, Alice's browser:
1. Decrypts the secret with her private key
2. Fetches Bob's public certificate from the API (public certs are not secret)
3. Encrypts the value with Bob's public certificate
4. POSTs the encrypted blob to the server — server stores it without seeing plaintext

Sync-on-update: Alice's browser re-encrypts for every recipient (O(N) RSA operations; WebCrypto handles this in milliseconds).

**Compromise recovery in E2E:**
- Browser generates a new RSA-4096 key pair (WebCrypto)
- Browser decrypts each secret with the old private key, re-encrypts with the new public key
- Browser AES-encrypts the new private key with the master password
- Browser POSTs: new encrypted private key + all re-encrypted secret blobs
- For 200 secrets: ~1-2 seconds. Frontend MUST show a progress indicator.

### External applications — local decrypt

External applications authenticate via RFC 7523 (JWT Bearer): the app signs a JWT with its private key, the server verifies against the stored public certificate. This proves the app holds the private key without transmitting it.

The server returns encrypted blobs. The application decrypts locally with its own private key. The private key never travels over the wire after initial registration (for generated key pairs) or ever (for CSR-registered apps).

### Internal Nextcloud applications — DecryptService

Applications running inside the same Nextcloud PHP runtime (e.g., OpenConnector) should not duplicate Keepiq's crypto logic. Keepiq exposes two stateless PHP services for this:

#### `DecryptService::decrypt(string $privateKeyPem, string $passphrase, string $encryptedBlob): string`

A pure crypto utility:
1. AES-decrypt the PEM private key using the passphrase
2. RSA-decrypt the encrypted blob using the unwrapped private key (handles chunking)
3. Return plaintext bytes

**Guarantees:**
- No database calls
- No calls to other Keepiq services (SecretService, EncryptionSuiteService, etc.)
- No entity context in memory — the service receives raw bytes and returns raw bytes
- The decrypted value cannot be linked to any Keepiq entity within the service
- Stateless — no caching, no side effects

#### `EncryptService::encrypt(string $publicCertPem, string $plaintext): string`

The companion for write operations:
1. RSA-encrypt the plaintext using the public certificate (handles chunking)
2. Return the encrypted blob

Same guarantees: no state, no database, no entity context.

#### Internal app flow

```php
// OpenConnector retrieving an API key from Keepiq

// 1. Get encrypted blob (via Keepiq's SecretService — returns blob, never decrypts)
$encryptedBlob = $secretService->getEncryptedValue($secretId, $appId);

// 2. Read own credentials from Nextcloud's credential store
$privateKey = $credentialsManager->retrieve($appId, 'keepiq_private_key');
$passphrase = $credentialsManager->retrieve($appId, 'keepiq_passphrase');

// 3. Decrypt using Keepiq's stateless crypto utility
$plaintext = $decryptService->decrypt($privateKey, $passphrase, $encryptedBlob);

// 4. Use the plaintext value
$connector->setApiKey($plaintext);
```

The calling app stores its private key and passphrase in `OCP\Security\ICredentialsManager` under its own app namespace. Keepiq's `DecryptService` never knows which app is calling or what the secret represents.

### Link shares and secret requests — always client-side

Link share decryption (Argon2id KDF → AES) always happens in the browser. The submitter of a secret request encrypts with the requester's public certificate in the browser. These flows are inherently client-side and unaffected by this architecture.

### Dual implementation requirement

Chunking, RSA encrypt/decrypt, and AES encrypt/decrypt must be implemented in both:
- **PHP (OpenSSL)** — used by `DecryptService` and `EncryptService` for internal Nextcloud apps
- **JavaScript (WebCrypto)** — used by browser users, link shares, and secret requests

The two implementations MUST produce identical ciphertext format so that data encrypted by one can be decrypted by the other. Integration tests MUST verify cross-implementation round-trips (encrypt in JS → decrypt in PHP, and vice versa).

## Consequences

**Positive:**
- True zero-knowledge — the server never decrypts secrets with entity context
- Master password never leaves the browser — immune to server-side compromise
- WebCrypto `extractable: false` limits XSS impact (cannot exfiltrate key material)
- Write-without-read natively supported by asymmetric encryption
- External applications are E2E by default — private key never travels after registration
- `DecryptService` gives internal apps a clean, stateless crypto API without duplicating logic
- `DecryptService` isolation means decrypted values in server memory are never linkable to entities
- Matches industry standard (Bitwarden, Passbolt, Proton Pass)
- No admin toggle complexity — one model, always E2E
- CA structure enables future Certificate Authority functionality

**Negative / trade-offs:**
- Dual crypto implementation (PHP + JS) — must be kept in sync and tested for cross-compatibility
- Chunking logic duplicated across two languages
- Sharing in E2E mode requires O(N) encrypt operations in the browser (acceptable for typical share counts)
- Compromise recovery runs entirely in browser — needs progress UI for large vaults
- Browser compatibility: requires WebCrypto API (all modern browsers; not IE11)
- More complex frontend — Pinia stores handle encrypted responses and decrypt client-side
- Internal apps must manage their own private key storage (in `ICredentialsManager`)
- Post-quantum vulnerability remains a known future risk

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| Server-side only (original ADR-003) | Server sees plaintext in entity context; does not meet zero-knowledge standard |
| Hybrid with admin toggle (E2E or server-side) | Unnecessary complexity — always E2E is simpler and more secure; internal apps use DecryptService |
| Hybrid with `X-Vault-Password` header detection | Per-request mode switching adds API complexity; always E2E is cleaner |
| Symmetric AES only | Cannot support write-without-read; server holds decrypt key — breaks security model |
| External KMS (AWS KMS, HashiCorp Vault) | Breaks Nextcloud-native design; adds external dependency and cost |
| Age / libsodium (modern alternatives to OpenSSL RSA) | Not yet universally available in PHP distributions; no WebCrypto equivalent for Age |

## Post-quantum note

Post-quantum cryptography algorithms are not yet available in stable PHP/OpenSSL or WebCrypto implementations (as of 2026-03-30). This architecture will need revisiting when post-quantum support becomes available in both runtimes.
