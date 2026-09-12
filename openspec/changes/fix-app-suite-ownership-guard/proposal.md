## Why

`EncryptionSuiteController::validateOwnership()` is the ownership gate for the three suite self-service endpoints a session may call on its own suite — `show` (`GET /suites/{id}`), `updatePrivateKey` (`PUT /suites/{id}/private-key`) and `revoke` (`POST /suites/{id}/revoke`). It guards the wrong condition:

```php
if ($suite->getOwnerType() === 'user' && $suite->getOwnerId() !== $userId) {
    throw ...; // "belongs to another user"
}
```

For an **application**-owned suite (`owner_type = 'application'`) the `=== 'user'` clause is false, so the whole condition is false and the check **passes**. Any authenticated non-admin can therefore act on an application's suite by id. `revoke` is destructive: it flips the suite to `revoked`, and a revoked suite blocks every read of the secrets bound to it — locking the application out of its own vault. `updatePrivateKey` writes to the application suite's row, and `show` discloses it cross-owner.

The gap was **confirmed on a running instance**: a non-admin user with no relationship to an application read its suite (`GET` → 200) and revoked it (`POST …/revoke` → 200, `status: revoked, revokedBy: <that user>`). By contrast, `POST /certificates/suites/{id}/reissue` on the same suite returned `403 "Only the suite owner or an admin may re-issue"` — because `CertificateLifecycleService::reissueSuite` expresses the identical intent correctly (`$ownsIt = ownerType === 'user' && ownerId === $userId; if (!$ownsIt && !$isAdmin) throw`). So the contract already exists in the codebase; `validateOwnership` is the one place that inverted it.

The `Revocation` requirement in the `encryption-suites` spec says "a user or administrator" may revoke, but never states the authorization **boundary** — that a user acts only on their own suite, and that application suites are not managed through these self-service endpoints. That silence is what let the bug through and what leaves gate-16 spec-coverage with nothing to hold the fix to. This change closes the code gap **and** records the boundary as a requirement so it cannot silently regress.

Severity note, stated plainly: exploiting `revoke` needs the application's suite id (a UUID). The non-admin-reachable application endpoints checked — `application#index` (returns application ids only) and `application#certificate` (returns `{id: appId, certificate}`; the certificate's CN is the app id and its serial is random) — do **not** expose the suite id, so a discovery path for a low-privilege user is not established and practical exploitability is below a session-only attack. The guard is wrong regardless: it fails open on a destructive operation, and the correct contract already exists one method over.

## What Changes

- `validateOwnership()` refuses unless the current user owns the suite as a user: `ownerType === 'user' && ownerId === $userId`. Any other suite — another user's, or any application's — is refused. This preserves self-service (a user managing their own suite) and removes the application-suite exposure on all three endpoints at once
- Add an `encryption-suites` requirement stating that suite self-service operations are owner-scoped, with scenarios for a foreign user suite and an application suite
- Add regression tests asserting `revoke` and `updatePrivateKey` refuse an application suite and never reach the service

Owner-only rather than owner-or-admin is deliberate: these endpoints are user self-service (the frontend only ever calls `revoke` on the caller's own current suite), and application-suite lifecycle is managed through the admin application endpoints, not here. Admins gain nothing by reaching application suites through the user self-service routes.

## Capabilities

### Modified Capabilities
- `encryption-suites`: adds the authorization boundary for suite self-service operations (`show`, `updatePrivateKey`, `revoke`) — owner-scoped, application suites excluded

## Impact

- **Database**: none
- **Backend**: one guard in `EncryptionSuiteController::validateOwnership()`; no signature or dependency change
- **Frontend**: none. The only caller of `revoke` targets the user's own current suite; `updatePrivateKey` and `show` likewise operate on the caller's own suite
- **API**: `show`/`updatePrivateKey`/`revoke` now refuse an application suite (or another user's suite) with the existing `Access denied` response — `show` continues to surface it as `404`, the destructive two as `403`. No change for a caller acting on its own suite
- **Security**: closes a cross-owner IDOR on a destructive operation. Confirmed empirically; recorded here as a requirement so gate-16 enforces it
- **Cross-app**: an application whose suite exists is no longer revocable by an unrelated user session; no positive capability is added to any caller
