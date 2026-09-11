# Keepiq — Architecture & Data Model

## 1. Overview

Keepiq is an encrypted secrets manager for Nextcloud — a password manager and key store for users and applications. It stores secrets (passwords, API keys, tokens, certificates) encrypted at rest using RSA-4096 public-key cryptography, with private keys protected by AES-256 encryption derived from a user's master password. A private Certificate Authority (root + intermediate) signs all user and application certificates.

### Architecture Pattern

```
┌─────────────────────────────────────────────────────────────┐
│  Keepiq Frontend (Vue 2 + Pinia)                           │
│  - Lock screen (master password entry)                      │
│  - Vault: secrets list with folder tree                     │
│  - Secret detail views (by type)                            │
│  - Sharing UI (user, link, request)                         │
│  - Key generator                                            │
│  - Dashboard (KPI cards, vault health)                      │
│  - Admin settings (CA health, app management)               │
│  - User settings (session timeout, notifications)           │
└──────────────┬──────────────────────────────────────────────┘
               │ REST API calls (Nextcloud session + X-Vault-Password)
┌──────────────▼──────────────────────────────────────────────┐
│  Keepiq PHP Backend                                         │
│  - Controllers: Secrets, EncryptionSuites, Applications,    │
│    Sharing, KeyGenerator, Settings                           │
│  - Services: EncryptionService, CertificateAuthorityService,│
│    SecretService, SharingService, MigrationService           │
│  - Entities: Doctrine ORM with Nextcloud migrations          │
│  - Encryption: OpenSSL (RSA-4096 + AES-256)                 │
└──────────────┬──────────────────────────────────────────────┘
               │ Doctrine ORM
┌──────────────▼──────────────────────────────────────────────┐
│  PostgreSQL Database                                         │
│  - Own tables (ISchemaWrapper migrations)                    │
│  - Encrypted fields: private keys (AES), secret values (RSA)│
│  - Unencrypted fields: names, URLs, metadata                │
└─────────────────────────────────────────────────────────────┘
```

Keepiq owns **all its database tables** — it does NOT use OpenRegister. This is an explicit exception to the org-wide ADR-001, justified by the security requirements of field-level encryption control and the need to eliminate intermediary services from the data path. See [ADR-001](../openspec/architecture/adr-001-own-database-tables.md).

## 2. Standards Research

Before defining our encryption architecture and data model, we evaluated multiple standards across cryptography, key management, and password security.

### 2.1 Standards Evaluated

| Standard | Type | Coverage | Maturity | Relevance |
|----------|------|----------|----------|-----------|
| **X.509 / RFC 5280** | International (IETF) | Certificate format, CA chains, certificate revocation, extensions | Very mature (since 1988) | **HIGH** — defines certificate structure for our private CA |
| **PKCS#1 / RFC 8017** | International (IETF) | RSA encryption and signature schemes, OAEP padding | Very mature | **HIGH** — RSA encryption of secrets |
| **PKCS#8 / RFC 5958** | International (IETF) | Private key storage format, encrypted private key wrapping | Very mature | **HIGH** — AES-encrypted private key storage |
| **PKCS#10 / RFC 2986** | International (IETF) | Certificate Signing Request (CSR) format | Very mature | **HIGH** — application registration via CSR |
| **NIST SP 800-57** | US Government | Key management lifecycle, key sizes, algorithm selection | Current (Rev. 5, 2020) | **HIGH** — RSA-4096 and AES-256 recommendations |
| **NIST SP 800-63B** | US Government | Authenticator assurance levels, memorized secret requirements | Current (Rev. 4 draft) | **HIGH** — master password strength policy |
| **OWASP Password Storage** | Industry (OWASP) | Password hashing, KDF selection (Argon2id, bcrypt, PBKDF2) | Active, regularly updated | **HIGH** — Argon2id for link share KDF |
| **OWASP Cryptographic Storage** | Industry (OWASP) | Encryption at rest, key management, algorithm selection | Active | **HIGH** — encryption architecture validation |
| **zxcvbn** | Industry (Dropbox) | Password strength estimation, entropy-based scoring (0–4) | Mature, widely adopted | **HIGH** — master password strength meter |
| **Argon2 / RFC 9106** | International (IETF) | Memory-hard KDF: Argon2d, Argon2i, Argon2id | Standardized 2021 | **HIGH** — link share snapshot encryption |
| **WebAuthn / FIDO2** | International (W3C) | Passwordless authentication, hardware key support | Mature, growing adoption | **LOW** — future: passwordless vault unlock |
| **Schema.org** | International (W3C) | Structured data vocabulary | Very mature | **LOW** — no standard types for secrets/credentials |

### 2.2 Design Principle: Security First, Standards-Based

> **All cryptographic operations use established standards via OpenSSL. No custom cryptography. The master password never touches the database.**

This means:
- RSA encryption follows **PKCS#1 v1.5 / OAEP** padding via OpenSSL
- Private keys are stored in **PKCS#8 PEM** format, AES-256 encrypted
- Certificates follow **X.509 v3** format, signed by the private CA intermediate
- CSR processing follows **PKCS#10** for application registration
- Key derivation for link shares uses **Argon2id** (memory-hard, RFC 9106)
- Master password strength is validated using **zxcvbn** entropy scoring aligned with NIST SP 800-63B

### 2.3 Key Findings

1. **X.509 / RFC 5280** defines the certificate format Keepiq uses for its private CA. The root certificate (20-year lifetime) signs an intermediate certificate (3-year lifetime), which in turn signs user and application certificates. This two-tier CA structure follows PKI best practice — the root key is used rarely, limiting exposure.

2. **NIST SP 800-57** recommends RSA-4096 for protection beyond 2031 (>128-bit security strength). AES-256 provides 256-bit security strength. Both exceed NIST's minimum recommendations for sensitive data protection. Key sizes in Keepiq are only allowed to increase, never decrease.

3. **NIST SP 800-63B** defines memorized secret (password) requirements: minimum 8 characters (we require 12), no composition rules (we use entropy-based scoring instead), and blocklist checking. Our zxcvbn-based approach aligns with NIST's shift from complexity rules to entropy measurement.

4. **OWASP Password Storage Cheat Sheet** recommends Argon2id as the first choice for password hashing / key derivation. We use Argon2id for link share snapshot encryption (deriving an AES key from the link password). For master password → AES key derivation, we use a similar KDF approach.

5. **PKCS#10 (CSR)** enables applications to register with Keepiq by submitting a Certificate Signing Request containing their public key. Keepiq signs the public key with the CA intermediate and never stores the application's private key — the application manages its own key externally. This is standard PKI practice for distributed systems.

6. **RSA chunking constraint** (PKCS#1): RSA-4096 can encrypt at most ~500 bytes per operation (key size in bytes − padding overhead). Secrets larger than this limit must be chunked. This is a known limitation documented in [ADR-003](../openspec/architecture/adr-003-rsa-aes-encryption-architecture.md).

7. **zxcvbn** scores passwords on a 0–4 scale: 0 = too guessable, 1 = very guessable, 2 = somewhat guessable, 3 = safely unguessable (resists online attacks, ~10^8 guesses), 4 = very unguessable (resists offline attacks, ~10^10 guesses). Keepiq requires score ≥ 3 by default, configurable up to 4 by admins.

8. **No applicable Schema.org types** — Schema.org has no standard vocabulary for secrets, credentials, or vault entries. Keepiq entities do not carry schema.org type annotations, unlike other Conduction apps that model semantic data.

## 3. Data Model Decisions

### 3.1 Standards Hierarchy

| Layer | Standard | Purpose |
|-------|----------|---------|
| **Cryptography** | RSA (PKCS#1), AES-256, OpenSSL | Encryption of secrets and private keys |
| **Certificate format** | X.509 v3 (RFC 5280), PKCS#8, PKCS#10 | CA certificates, key storage, CSR processing |
| **Key derivation** | Argon2id (RFC 9106) | Link share password → AES key derivation |
| **Password policy** | NIST SP 800-63B + zxcvbn | Master password strength enforcement |
| **Key management** | NIST SP 800-57 | Key size selection, lifecycle management |
| **Nextcloud native** | OCP interfaces | Users, sessions, notifications, search, files |

### 3.2 Entity Definitions

#### EncryptionSuite

The cryptographic identity of a user or application. Holds a public certificate (for encrypting secrets) and an AES-encrypted private key (for decrypting them). See [encryption-suites spec](../openspec/specs/encryption-suites/spec.md) for full requirements.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Standard** | X.509 v3 certificate + PKCS#8 private key | Industry-standard PKI formats |
| **Ownership** | Polymorphic: `owner_type` (user \| application) + `owner_id` | Single table, single query path — see [ADR-002](../openspec/architecture/adr-002-polymorphic-encryption-suite-ownership.md) |
| **Key size** | RSA-4096 minimum (only allowed to increase) | NIST SP 800-57 recommendation for long-term protection |
| **Private key protection** | AES-256 encrypted with master password derived key | Master password never stored — see [ADR-003](../openspec/architecture/adr-003-rsa-aes-encryption-architecture.md) |
| **Certificate DN** | Default DN fields + owner-specific CN | See certificate DN section below |

**Core properties:**

| Property | Type | Encrypted | Standard | Notes |
|----------|------|-----------|----------|-------|
| `id` | UUID | No | — | Primary key |
| `owner_type` | enum: user, application | No | ADR-002 | Polymorphic ownership |
| `owner_id` | string | No | — | Nextcloud user ID or Application ID |
| `certificate` | text (PEM) | No | X.509 v3 | Public certificate signed by CA intermediate |
| `private_key` | text (PEM) | Yes (AES-256) | PKCS#8 | Encrypted with master password derived key |
| `status` | enum: active, revoked, compromised | No | X.509 CRL concept | Lifecycle state |
| `revoked_at` | datetime | No | — | Audit: when revoked |
| `revoked_reason` | string | No | X.509 CRL reason | Audit: why revoked |
| `revoked_by` | string | No | — | Audit: who revoked |
| `reinstated_at` | datetime | No | — | Audit: when reinstated |
| `reinstated_by` | string | No | — | Audit: who reinstated |
| `created_at` | datetime | No | — | — |

#### Certificate Distinguished Name (DN)

All certificates issued by the Keepiq CA share a common set of DN fields, with the `commonName` varying by certificate type:

**Default DN fields** (defined as `DEFAULT_DN` in `CertificateAuthorityService`):

| Field | Value |
|-------|-------|
| `countryName` | NL |
| `stateOrProvinceName` | Noord-Holland |
| `localityName` | Amsterdam |
| `organizationName` | Conduction |
| `organizationalUnitName` | Keepiq |

**Common Name by certificate type:**

| Certificate | Common Name | Example |
|-------------|-------------|---------|
| Root CA | `Keepiq Root CA` | `Keepiq Root CA` |
| Intermediate CA | `Keepiq Intermediate CA` | `Keepiq Intermediate CA` |
| User certificate | Federated cloud ID (user@instance), falls back to user ID | `admin@nextcloud.local` |
| Application certificate | Application ID | `a1b2c3d4-...` |

The cloud ID is resolved via `IUserManager::get($ownerId)->getCloudId()` in `EncryptionSuiteService::resolveCommonName()`. This ensures user certificates contain the full federated identity. When a certificate is re-signed during CA renewal, the original CN is preserved by extracting it from the existing certificate via `openssl_x509_parse()`.

#### CACertificate

The private Certificate Authority that signs all user and application certificates.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Structure** | Two-tier: root (20yr) → intermediate (3yr) | PKI best practice — root key used rarely |
| **Standard** | X.509 v3 certificates | Industry standard |
| **Renewal** | Intermediate auto-renews; root requires manual admin action | Minimize disruption; root rotation is rare |

**Core properties:**

| Property | Type | Encrypted | Standard | Notes |
|----------|------|-----------|----------|-------|
| `id` | UUID | No | — | Primary key |
| `type` | enum: root, intermediate | No | PKI convention | Certificate tier |
| `certificate` | text (PEM) | No | X.509 v3 | Public certificate |
| `private_key` | text (PEM) | Yes (AES-256) | PKCS#8 | Only present for intermediate |
| `is_active` | bool | No | — | Only one intermediate active for signing |
| `expires_at` | datetime | No | X.509 validity | For efficient expiry queries |
| `revoked_at` | datetime | No | X.509 CRL | Set on forced revocation |
| `successor_id` | FK | No | — | Points to replacement certificate |
| `created_at` | datetime | No | — | — |

#### Secret

The core data entity — holds sensitive information encrypted with the owner's public certificate.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Encryption** | RSA-4096 (PKCS#1) per owner's public certificate | Asymmetric: enables write-without-read |
| **Searchable fields** | `name` and `url` stored unencrypted | Enables search and Nextcloud unified search without master password |
| **Folder organization** | Tree via `parent_id` on Folder entity | User-managed hierarchy, no stored path strings |

**Core properties:**

| Property | Type | Encrypted | Standard | Notes |
|----------|------|-----------|----------|-------|
| `id` | UUID | No | — | Primary key |
| `name` | string | No | — | Human-readable label — safe for lists/search |
| `url` | string | No | — | Target URL — enables search without decryption |
| `type_id` | FK | No | — | SecretType reference; defaults to `login` |
| `folder_id` | FK | No | — | Folder reference; null = root level |
| `key` | text | Yes (RSA) | PKCS#1 | The actual secret value |
| `login` | string | Yes (RSA) | — | Username, client ID, or equivalent |
| `additional_fields` | text | Yes (RSA) | — | JSON blob of extra key-value pairs |
| `encryption_suite_id` | FK | No | — | Which suite encrypted this secret |
| `owner_type` | enum: user, application | No | ADR-002 | Polymorphic ownership |
| `owner_id` | string | No | — | Nextcloud user ID or Application ID |
| `possibly_compromised_at` | datetime | No | — | Set during compromise recovery |
| `migration_error` | text | No | — | Set on re-encryption failure |
| `created_at` | datetime | No | — | — |
| `updated_at` | datetime | No | — | — |

#### SecretType

Defines the type of a secret — a UI hint that drives how the interface labels and presents fields.

| Property | Type | Notes |
|----------|------|-------|
| `id` | UUID | Primary key |
| `name` | string | Slug identifier (e.g., `api_key`) — unique |
| `label` | string | Human-readable (e.g., "API Key") |
| `scope` | enum: system, user, global | `system` = built-in; `user` = per-user; `global` = admin-created |
| `owner_id` | string | Nextcloud user ID for `user` scope; null for system/global |
| `created_at` | datetime | — |

**System types** (seeded on install, immutable): `login`, `api_key`, `ssh_key`, `certificate`, `note`, `database`

#### Folder

Organizes secrets into a per-user tree hierarchy.

| Property | Type | Notes |
|----------|------|-------|
| `id` | UUID | Primary key |
| `name` | string | Single path segment, no slashes |
| `parent_id` | FK (Folder) | Null = root level |
| `owner_type` | enum: user, application | Polymorphic ownership |
| `owner_id` | string | Nextcloud user ID or Application ID |
| `created_at` | datetime | — |
| `updated_at` | datetime | — |

#### Application

An external or internal application registered to use Keepiq as a secret store.

| Property | Type | Notes |
|----------|------|-------|
| `id` | UUID | Primary key |
| `name` | string | Human-readable name |
| `type` | enum: internal, external | Informational; no functional difference currently |
| `status` | enum: pending, active | Non-admin registrations start as `pending` |
| `registered_by` | string | Nextcloud user ID or null (anonymous) |
| `approved_by` | string | Admin user ID; null if pending |
| `created_at` | datetime | — |
| `approved_at` | datetime | — |

#### SecretShare (User-to-User)

Links an original secret to an encrypted copy in a recipient's vault.

| Property | Type | Notes |
|----------|------|-------|
| `id` | UUID | Primary key |
| `source_secret_id` | FK (Secret) | The original secret |
| `target_user_id` | string | Recipient's Nextcloud user ID |
| `secret_id` | FK (Secret) | The encrypted copy in recipient's vault |
| `group_share_id` | FK (GroupShare) | Nullable — set if created from a group share |
| `created_at` | datetime | — |

#### GroupShare

Tracks that a secret has been shared with a Nextcloud user group.

| Property | Type | Notes |
|----------|------|-------|
| `id` | UUID | Primary key |
| `secret_id` | FK (Secret) | The secret shared with the group |
| `group_id` | string | Nextcloud group ID |
| `created_by` | string | Owner who created the share |
| `created_at` | datetime | — |

#### SecretDelegation

Tracks ownership delegation of a secret.

| Property | Type | Notes |
|----------|------|-------|
| `id` | UUID | Primary key |
| `secret_id` | FK (Secret) | The delegated secret |
| `original_owner_id` | string | Original owner's user ID |
| `delegated_to` | string | Delegate's user ID |
| `delegated_at` | datetime | — |
| `initiated_by` | string | Who created the delegation |
| `is_permanent` | bool | False = temporary; true = permanent (owner's suite revoked) |
| `made_permanent_at` | datetime | Null while temporary |

#### LinkShare

A password-protected link for sharing a secret snapshot with external parties.

| Property | Type | Encrypted | Notes |
|----------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The secret being shared |
| `token` | string | No | URL-safe random token (≥128 bits entropy) |
| `encrypted_secret_snapshot` | text | Yes (AES-256 via Argon2id KDF) | Point-in-time snapshot |
| `encryption_suite_id` | FK | No | Suite used to encrypt the snapshot |
| `usage_limit` | int | No | Max accesses; null = unlimited |
| `usage_count` | int | No | Current access count |
| `created_at` | datetime | No | — |
| `expires_at` | datetime | No | Optional expiry |

**Security:** Link password is never stored. Snapshot is encrypted with AES-256 using a key derived from the link password via Argon2id (RFC 9106). After 5 consecutive failed attempts, the link share is permanently deleted.

#### SecretRequest

A fill-in link for write-without-read secret submission.

| Property | Type | Notes |
|----------|------|-------|
| `id` | UUID | Primary key |
| `secret_id` | FK (Secret) | The unfilled Secret this request writes to |
| `encryption_suite_id` | FK | Public certificate used to encrypt submitted values |
| `token` | string | URL-safe random token (≥128 bits entropy) |
| `requested_fields` | JSON | Array of field names being requested |
| `status` | enum: pending, locked, fulfilled | `locked` during compromise recovery |
| `expires_at` | datetime | Optional expiry |
| `created_at` | datetime | — |
| `fulfilled_at` | datetime | — |

#### SuiteMigration

Tracks compromise recovery migrations.

| Property | Type | Notes |
|----------|------|-------|
| `id` | UUID | Primary key |
| `old_suite_id` | FK (EncryptionSuite) | Compromised suite |
| `new_suite_id` | FK (EncryptionSuite) | Replacement suite |
| `status` | enum: in_progress, completed, completed_with_errors | Migration state |
| `started_at` | datetime | — |
| `completed_at` | datetime | Null while in progress |

### 3.3 Encryption Flow Summary

```
                        Master Password (user input, never stored)
                              │
                              ▼
                     ┌─────────────────┐
                     │   KDF (derive)  │
                     └────────┬────────┘
                              │
                              ▼
                     AES-Derived Key (stored in session only)
                              │
                    ┌─────────┴─────────┐
                    ▼                   ▼
           ┌───────────────┐   ┌───────────────┐
           │ Decrypt       │   │ Encrypt        │
           │ Private Key   │   │ Private Key    │
           │ (AES-256)     │   │ (AES-256)      │
           └───────┬───────┘   └───────────────┘
                   │
                   ▼
            Private Key (in memory only)
                   │
         ┌─────────┴─────────┐
         ▼                   ▼
┌───────────────┐   ┌───────────────┐
│ Decrypt       │   │ Encrypt        │
│ Secrets       │   │ Secrets        │
│ (RSA-4096)    │   │ (RSA-4096)     │
└───────────────┘   └───────────────┘
```

**Write-without-read property:** Because encryption uses the owner's **public** certificate (available to anyone), any party can write a secret into another user's or application's vault. Only the private key holder can decrypt — and the private key requires the master password. This is a direct consequence of asymmetric cryptography and is fundamental to secret requests and application secret management.

### 3.4 Nextcloud Integration Strategy

**Principle: Keepiq handles all encryption and secret storage itself. Nextcloud provides identity, session, notification, and search infrastructure.**

#### REUSE from Nextcloud

| Feature | OCP Interface | What to Reuse | How |
|---------|--------------|---------------|-----|
| **Users** | `OCP\IUserManager` | Authentication identity, vault ownership | Reference by Nextcloud user UID |
| **Groups** | `OCP\IGroupManager` | Group sharing, vault_admin role | Query group membership for group shares |
| **Session** | `OCP\ISession` | Store AES-derived key during vault session | `ISession::set('keepiq_aes_key', $derivedKey)` |
| **Notifications** | `OCP\Notification\IManager` | Share received, request fulfilled, CA expiry, app approval | Implement `INotifier` for rendering |
| **Search** | `OCP\Search\IProvider` | Unified search (Ctrl+F) for secrets by name/URL | Query name + url without AES key; deep-link to secret |
| **Settings** | `OCP\Settings\ISettings` | Admin settings panel (CA health, password policy) | Register admin section |
| **App Config** | `OCP\IAppConfig` | App-wide settings (min password length, session timeout) | Store admin-configurable values |
| **User Config** | `OCP\IConfig` | Per-user settings (session timeout preference, notification toggles) | Store user preferences |
| **User Deletion** | `OCP\User\Events\UserDeletedEvent` | Clean up EncryptionSuite, secrets, shares on user delete | Event listener |
| **Group Membership** | `OCP\Group\Events\UserAddedEvent` / `UserRemovedEvent` | Trigger group share add/revoke | Event listeners |

#### BUILD in Keepiq (Security-specific)

| What | Why Not Reuse |
|------|---------------|
| **EncryptionSuites & CA** | Core security model — no Nextcloud equivalent |
| **Secret storage (encrypted)** | Field-level encryption with per-user keys — cannot use generic storage |
| **Master password session** | Custom session management with configurable timeout and tab-close detection |
| **Key generator** | Server-side cryptographic randomness with configurable rules |
| **Sharing (user/link/request)** | Encryption-aware sharing — each share is a re-encrypted copy |
| **Suite migration** | Compromise recovery with re-encryption — domain-specific |
| **Application management** | CSR processing, approval queue — domain-specific PKI |

#### Key OCP Interface Examples

```php
// Session — store AES-derived key
$session = \OCP\Server::get(\OCP\ISession::class);
$session->set('keepiq_aes_key', $aesKey);
$session->get('keepiq_aes_key'); // retrieve for decryption

// Notifications — secret shared
$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
$notification = $manager->createNotification();
$notification->setApp('keepiq')
    ->setUser($recipientId)
    ->setSubject('secret_shared', ['sharer' => $sharerId])
    ->setObject('secret', $secretId);
$manager->notify($notification);

// Search — register unified search provider
class SecretSearchProvider implements \OCP\Search\IProvider {
    public function search(\OCP\IUser $user, \OCP\Search\ISearchQuery $query): SearchResult {
        // Query name + url columns directly — no AES key needed
        $secrets = $this->secretMapper->searchByNameOrUrl($user->getUID(), $query->getTerm());
        // Return results with deep-link URLs (via lock screen if session inactive)
    }
}

// User deletion cleanup
class UserDeletedListener implements \OCP\EventDispatcher\IEventListener {
    public function handle(\OCP\EventDispatcher\Event $event): void {
        // Delete user's EncryptionSuite, secrets, shares, folders
    }
}

// Group membership — auto-revoke group shares
class UserRemovedFromGroupListener implements \OCP\EventDispatcher\IEventListener {
    public function handle(\OCP\EventDispatcher\Event $event): void {
        // Delete all SecretShares where group_share_id references a GroupShare for this group
    }
}
```

### 3.5 @conduction/nextcloud-vue Library

Keepiq uses its own database (not OpenRegister), so **store-level** components (`useObjectStore`, plugins) do not apply. However, the following **UI components and patterns** from `@conduction/nextcloud-vue` are used:

| Layer | What to Use | Purpose |
|-------|-------------|---------|
| **Components** | `CnDataTable`, `CnFilterBar`, `CnStatusBadge`, `CnEmptyState`, `CnPagination` | Consistent list views for secrets, applications, shares |
| **Settings** | `CnSettingsSection`, `CnVersionInfoCard`, `CnSettingsCard` | Admin settings page (CA health, password policy, app management) |
| **Detail pages** | `CnDetailPage`, `CnDetailCard`, `CnObjectSidebar` | Secret detail view with card-based layout + sidebar (files, notes, tags, audit trail) |
| **CSS** | NL Design System double-fallback pattern (cn- prefix) | Government theming compliance |
| **Not used** | `useObjectStore`, `registerMappingPlugin`, `lifecyclePlugin`, `useListView`, `useDetailView` | These depend on OpenRegister API — Keepiq has its own backend API |

Keepiq implements its own Pinia stores (`useSecretStore`, `useEncryptionSuiteStore`, `useApplicationStore`, etc.) that call Keepiq's REST API instead of the OpenRegister API. The stores follow the same patterns (loading state, pagination, CRUD) but are not derived from `useObjectStore`.

**package.json dependency** (MUST be present):
```json
"@conduction/nextcloud-vue": "^0.1.0-beta.1"
```

**Webpack alias** (MUST be in webpack.config.js):
```js
const fs = require('fs')
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

// In resolve.alias:
...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
'vue$': path.resolve(__dirname, 'node_modules/vue'),
'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
```

### 3.6 Vue Router (Navigation)

All navigation uses Vue Router (hash mode). The lock screen is a **route guard** — if no AES key is in session, all routes redirect to the lock screen.

| Path | Name | Component | Props |
|------|------|-----------|-------|
| `/` | Dashboard | Dashboard | — |
| `/lock` | Lock | LockScreen | `route => ({ returnUrl: route.query.returnUrl })` |
| `/secrets` | Secrets | SecretList | — |
| `/secrets/:id` | SecretDetail | SecretDetail | `route => ({ secretId: route.params.id })` |
| `/folders/:id` | FolderView | SecretList | `route => ({ folderId: route.params.id })` |
| `/applications` | Applications | ApplicationList | — |
| `/applications/:id` | ApplicationDetail | ApplicationDetail | `route => ({ applicationId: route.params.id })` |
| `/share/link/:token` | LinkShareAccess | LinkShareAccess | `route => ({ token: route.params.token })` |
| `/share/request/:token` | SecretRequestFill | SecretRequestFill | `route => ({ token: route.params.token })` |
| `*` | — | redirect → `/` | — |

**Route guard pattern:**
```js
router.beforeEach((to, from, next) => {
  // Public routes (link share, request fill-in) skip the lock screen
  if (['LinkShareAccess', 'SecretRequestFill', 'Lock'].includes(to.name)) {
    return next()
  }
  // Check if AES key is in session (API call to backend)
  if (!store.isUnlocked) {
    return next({ name: 'Lock', query: { returnUrl: to.fullPath } })
  }
  next()
})
```

Key files: `src/router/index.js`, registered in `main.js`, `<router-view />` in `App.vue`.
MainMenu navigation: use `:to` prop on `NcAppNavigationItem` (NOT `@click` + `$router.push()`).

The MainMenu footer contains two items: **"Lock vault"** (lock icon — calls `session.lock()` and navigates to `/lock`) and **"Settings"** (gear icon — opens `NcAppSettingsDialog` for user preferences).

### 3.7 OpenConnector Integration

Keepiq acts as a **secret store for OpenConnector**. When OpenConnector needs API keys or credentials to connect to external services, it can retrieve them from Keepiq's application vault via the API.

**Integration pattern:**
1. Register OpenConnector as an Application in Keepiq (admin auto-approved)
2. OpenConnector receives an EncryptionSuite (via generated key pair or CSR)
3. Admin writes API keys/credentials into OpenConnector's application vault
4. OpenConnector retrieves its secrets via Keepiq's API using `X-Vault-Password` header

This integration is via Keepiq's REST API — no tight coupling or shared database.

## 4. Database Configuration

### Doctrine Entities

All entities use Nextcloud's `ISchemaWrapper` migration pattern. Migrations are stored in `lib/Migration/`.

| Entity | Table Name | Notes |
|--------|-----------|-------|
| EncryptionSuite | `keepiq_encryption_suites` | Composite index on `(owner_type, owner_id)` |
| CACertificate | `keepiq_ca_certificates` | — |
| Secret | `keepiq_secrets` | Index on `(owner_type, owner_id)`, `folder_id`, `encryption_suite_id` |
| SecretType | `keepiq_secret_types` | Unique index on `name` |
| Folder | `keepiq_folders` | Index on `(owner_type, owner_id, parent_id)` |
| Application | `keepiq_applications` | — |
| SecretShare | `keepiq_secret_shares` | Index on `source_secret_id`, `target_user_id` |
| GroupShare | `keepiq_group_shares` | Index on `(secret_id, group_id)` |
| SecretDelegation | `keepiq_secret_delegations` | Index on `secret_id` |
| LinkShare | `keepiq_link_shares` | Unique index on `token` |
| SecretRequest | `keepiq_secret_requests` | Unique index on `token` |
| SuiteMigration | `keepiq_suite_migrations` | — |

### 4.1 Public endpoint rate limits

Every controller method registered with `#[PublicPage]` (reachable without a
Nextcloud session) also carries a Nextcloud-native
`#[AnonRateLimit(limit: N, period: 60)]` attribute. Limits are sized per
endpoint sensitivity — tighter for the highest-value credential-custody
targets, looser for polling machine clients — and are intentionally
documented here so a future PR loosening one has to consciously edit
documented intent rather than silently drop a limit during refactoring.

| Controller::method | Limit | Rationale |
|---|---|---|
| `ApplicationTokenController::exchange` | 10 / 60s | JWT-bearer token exchange (`POST /api/v1/token`) is the single highest-value target — a successful forced/guessed exchange yields a bearer token good for reading an application's secrets. Legitimate callers retry infrequently. |
| `LinkShareAccessController::show` | 15 / 60s | Phase 1 of the public link-share access protocol. Composes with the existing `recordFailedAttempt` domain-level brute-force counter (auto-delete after N failed attempts): the rate limit rejects with 429 on an over-limit burst *before* the domain counter increments, so a rate-limited request is never double-counted as a "failed attempt". |
| `LinkShareAccessController::confirm` | 15 / 60s | Phase 2 confirmation of a successful client-side decryption. Legitimate recipients make a handful of requests per session, not bursts. |
| `SecretRequestFillController::show` | 20 / 60s | Public token-based recipient fill-in flow, phase 1 (resolve token metadata). |
| `SecretRequestFillController::fill` | 20 / 60s | Fill-in flow phase 2 (submit encrypted fields). |
| `ApplicationSecretsController::index` | 30 / 60s | Bearer-authenticated but the route is `#[PublicPage]` so anonymous traffic reaches the controller before `JwtAuthMiddleware` validates the header. Limit protects the pre-auth surface; generous enough for a legitimate polling machine client. |
| `ApplicationSecretsController::show` | 30 / 60s | Same rationale as `index`. |
| `ApplicationController::create` | 10 / 60s | Anonymous application self-registration — only reachable when an admin opts in via `anonymous_application_registration_enabled`. Admins enabling anonymous registration inherit this rate limit. |
| `MachineLeaseController::index` | 30 / 60s | Bearer-authenticated lease list (machine-secret-leases §4.1); `#[PublicPage]` pre-auth surface, same polling profile as the secrets endpoints. |
| `MachineLeaseController::renew` | 30 / 60s | Lease renewal — legitimate connectors renew at most once per default-TTL window. |
| `MachineLeaseController::revoke` | 30 / 60s | Lease self-revocation — rare in legitimate use; the limit caps abuse of the rotation-flag side effect. |
| `EphemeralSendAccessController::peek` | 15 / 60s | Anonymous ephemeral-send metadata (ephemeral-send §4.2); the ≥256-bit token is the real access control, the limit caps enumeration attempts. |
| `EphemeralSendAccessController::access` | 15 / 60s | Ciphertext fetch phase of the two-phase protocol; a view is only consumed on confirm. |
| `EphemeralSendAccessController::confirm` | 15 / 60s | Decrypt confirmation (consumes a view, burns at cap). |
| `EphemeralSendAccessController::failure` | 15 / 60s | Failed-password reporting (burns the send at 5); the limit caps deliberate burn-griefing bursts. |

All limits are keyed anonymously (per-IP) by Nextcloud's rate-limiter
middleware, which is available since NC 24; Keepiq's `info.xml` floor
(NC 31) already satisfies this.

### 4.2 Vault-key proofs on destructive operations

The always-E2E model (ADR-003) makes *reading* the vault cryptographically
gated on the master password, but leaves *writing* gated only by the Nextcloud
session, because writing a secret needs only the owner's public key. For the
operations that can render vault contents or key material **permanently
unreadable**, a session is not enough: those carry a **vault-key proof** — a
signature, made with the owner's suite private key, over a server-issued
challenge bound to the operation's own parameters. Because that private key is
recoverable only by decrypting its envelope with the master password, a verified
proof is a server-verifiable proof of the master password. This closes the
session-only lockout (issue #395): a stolen cookie, leaked app password, or XSS
in an unlocked tab can no longer destroy a vault.

Enforced declaratively by `#[VaultKeyProofRequired(binds, subject, purpose)]`
(`lib/Attribute/`) + `VaultKeyProofMiddleware`, with the crypto in
`VaultKeyProofService`. The guarded routes:

| Controller::method | `subject` | `binds` | `purpose` |
|---|---|---|---|
| `EncryptionSuiteController::compromiseRecovery` | `active` (old suite) | `publicKey`, `encryptedPrivateKey` | `compromise-recovery` |
| `EncryptionSuiteController::updatePrivateKey` | `routeParam:id` | `encryptedPrivateKey` | `update-private-key` |
| `MigrationController::complete` | `migrationOldSuite` | `id` | `complete-migration` |
| `EmergencyAccessController::destroy` | `active` | `id` | `emergency-access-destroy` |

Load-bearing design points — change these only deliberately:

- **Sign, never decrypt.** The proof is a *signature*. A decrypt challenge would
  be satisfiable by the session `CryptoKey`, which is imported non-extractable
  and `['decrypt']`-only — so an unlocked tab (and thus injected script) could
  answer it. Signing needs the raw private key, re-imported with `['sign']` from
  bytes that exist only while the freshly entered master password is in hand.
- **The attribute carries the binding.** The middleware cannot read the request
  body (the framework decodes JSON and drops the raw bytes), so the proof
  commits to *named* request parameters, each hashed and concatenated in
  declared order. No JSON-canonicalisation agreement between JS and PHP is
  needed; cross-language interop is pinned by `VaultKeyProofCrossImplTest`.
- **Stateless, expiring challenges.** The nonce is HMAC-authenticated with the
  instance secret over its random part, the caller, the purpose and an expiry —
  no server-side store. Deliberately **not** `ICacheFactory`: a null cache on a
  default install would break the flow. Single-use enforcement is unnecessary
  because the signature commits to the operation's parameters, so a replay only
  ever re-authorises the byte-identical operation.
- **Not waived for any session type.** The middleware consults no auth backend
  and no token scope, so it behaves identically on SSO, app-password and
  ordinary sessions — its authority is key material, not the login method.
- **`complete` proves the OLD key** (`migrationOldSuite`), not the new one: at
  completion both suites are active so `active` is ambiguous, and the old key is
  the one both the initiate and resume clients already hold the password for.
- **Abort is deliberately unguarded.** `MigrationController::abort` is
  restorative (it returns the vault to the still-active old suite), so requiring
  a proof would leave a vault wedged by an unauthorised rotation wedged.

**A new route that can irreversibly destroy vault data MUST be added to
`tests/Unit/Controller/VaultKeyProofAttributesTest.php`.** A declarative guard
fails *open* when it is omitted — nothing errors, the attribute is just absent —
so that reflection test enumerates the guarded routes and fails the build if one
loses its attribute or has its binding/subject/purpose loosened. The test also
carries a documented exclusion list (`proofChallenge`, `abort`).

## 5. Open Research Questions

1. **Application API authentication** — RFC 7523 (JWT Bearer / Private Key JWT) is the lean for how approved applications authenticate to retrieve secrets. Uses existing RSA key infrastructure, short-lived tokens, no new credential. Needs team discussion before finalizing. See [application-mgmt spec](../openspec/specs/application-mgmt/spec.md).

2. **Pagination approach** — 50 items per page (standard pagination) or 30 items with dynamic infinite scroll? To be decided during UI design.

3. **Subfolder cascade** — Does `?cascade=delete` and `?cascade=move` apply recursively to subfolders, or only to direct contents? Unclear whether recursive folder deletion should be allowed.

4. **Internal application master password** — How does a Nextcloud app running a cronjob authenticate to its Keepiq vault? No solution found that doesn't compromise the security model. See Vault-app.docx for full analysis.

5. **Post-quantum cryptography** — RSA-4096 is vulnerable to future quantum attacks. Post-quantum algorithms are not yet available in stable PHP/OpenSSL. Must be revisited when PHP gains support.

6. **Forced intermediate revocation and secret compromise** — When an admin force-revokes the intermediate certificate (e.g., leaked key), should all secrets be flagged `possibly_compromised_at`? The intermediate signs certificates but doesn't directly encrypt secrets. Needs further analysis.

## 6. References

### Cryptographic Standards
- [RFC 5280 — X.509 PKI Certificate Profile](https://www.rfc-editor.org/rfc/rfc5280.html) — Certificate format and CA chain
- [RFC 8017 — PKCS#1 RSA Cryptography](https://www.rfc-editor.org/rfc/rfc8017.html) — RSA encryption schemes
- [RFC 5958 — PKCS#8 Private Key Info](https://www.rfc-editor.org/rfc/rfc5958.html) — Encrypted private key storage
- [RFC 2986 — PKCS#10 CSR](https://www.rfc-editor.org/rfc/rfc2986.html) — Certificate Signing Request format
- [RFC 9106 — Argon2](https://www.rfc-editor.org/rfc/rfc9106.html) — Memory-hard KDF

### Key Management & Password Policy
- [NIST SP 800-57 Part 1](https://csrc.nist.gov/pubs/sp/800/57/pt1/r5/final) — Key management recommendations
- [NIST SP 800-63B](https://pages.nist.gov/800-63-3/sp800-63b.html) — Authenticator assurance levels
- [OWASP Password Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html)
- [OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
- [zxcvbn (Dropbox)](https://github.com/dropbox/zxcvbn) — Password strength estimation

### Nextcloud OCP Interfaces
- [OCP\ISession](https://docs.nextcloud.com/) — Server-side session storage
- [OCP\Notification\IManager](https://docs.nextcloud.com/) — Notification system
- [OCP\Search\IProvider](https://docs.nextcloud.com/) — Unified search integration
- [OCP\Settings\ISettings](https://docs.nextcloud.com/) — Admin settings registration

### Architectural Decisions
- [ADR-001: Own Database Tables](../openspec/architecture/adr-001-own-database-tables.md)
- [ADR-002: Polymorphic Encryption Suite Ownership](../openspec/architecture/adr-002-polymorphic-encryption-suite-ownership.md)
- [ADR-003: RSA + AES Encryption Architecture](../openspec/architecture/adr-003-rsa-aes-encryption-architecture.md)
