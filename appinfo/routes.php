<?php

declare(strict_types=1);

/*
 * Keepiq route table.
 *
 * The canonical AppHost plumbing routes (dashboard page + SPA catch-all,
 * settings index/create/load, per-user preferences, and the observability
 * endpoints health#index / metrics#index) are provided by
 * \OCA\OpenRegister\AppHost\Routes::standard(). The /api/health and
 * /api/metrics URLs are unchanged; their controllers are aliased to the
 * AppHost generic controllers by Bootstrap::register() in Application.php.
 *
 * Every Keepiq domain route is appended via $extra below — it is inserted
 * before the SPA catch-all so it keeps priority over the /{path} fallback.
 * This file references no OCA\OpenRegister symbol other than the pure array
 * builder Routes::standard(), so it is safe to require even when OpenRegister
 * is disabled.
 */

return \OCA\OpenRegister\AppHost\Routes::standard([
    // Dashboard summary (domain aggregator — DashboardController::summary()).
    ['name' => 'dashboard#summary', 'url' => '/api/dashboard/summary', 'verb' => 'GET'],

    // Admin + user settings split (implement-dashboard-settings §2.4).
    ['name' => 'settings#getAdminSettings',    'url' => '/api/settings/admin', 'verb' => 'GET'],
    ['name' => 'settings#updateAdminSettings', 'url' => '/api/settings/admin', 'verb' => 'PUT'],
    ['name' => 'settings#getUserSettings',     'url' => '/api/settings/user',  'verb' => 'GET'],
    // Read-only org password policy for write dialogs (org-password-policies §1.3).
    ['name' => 'settings#getPolicy',           'url' => '/api/settings/policy', 'verb' => 'GET'],
    ['name' => 'settings#updateUserSettings',  'url' => '/api/settings/user',  'verb' => 'PUT'],

    // EncryptionSuite CRUD.
    ['name' => 'encryptionSuite#index',             'url' => '/api/v1/suites',                          'verb' => 'GET'],
    ['name' => 'encryptionSuite#show',              'url' => '/api/v1/suites/{id}',                     'verb' => 'GET'],
    ['name' => 'encryptionSuite#create',            'url' => '/api/v1/suites',                          'verb' => 'POST'],
    ['name' => 'encryptionSuite#updatePrivateKey',  'url' => '/api/v1/suites/{id}/private-key',         'verb' => 'PUT'],
    ['name' => 'encryptionSuite#revoke',            'url' => '/api/v1/suites/{id}/revoke',              'verb' => 'POST'],
    ['name' => 'encryptionSuite#reinstate',         'url' => '/api/v1/suites/{id}/reinstate',           'verb' => 'POST'],
    ['name' => 'encryptionSuite#compromiseRecovery','url' => '/api/v1/suites/compromise-recovery',      'verb' => 'POST'],

    // CA management (admin-only).
    ['name' => 'cACertificate#getStatus',          'url' => '/api/v1/ca/status',                      'verb' => 'GET'],
    ['name' => 'cACertificate#retryBootstrap',     'url' => '/api/v1/ca/bootstrap-retry',              'verb' => 'POST'],
    ['name' => 'cACertificate#renewIntermediate',  'url' => '/api/v1/ca/renew-intermediate',           'verb' => 'POST'],
    ['name' => 'cACertificate#renewRoot',          'url' => '/api/v1/ca/renew-root',                   'verb' => 'POST'],

    // Migration tracking.
    ['name' => 'migration#getStatus',                'url' => '/api/v1/migrations/status',               'verb' => 'GET'],
    ['name' => 'migration#complete',                 'url' => '/api/v1/migrations/{id}/complete',        'verb' => 'POST'],

    // Compromise-recovery migration work loop. One record per request: the
    // browser decrypts with the old private key, re-encrypts under the new one,
    // proves the round-trip, and posts the ciphertext here. Store-specific
    // paths rather than a generic {store, id} — authorization differs per store
    // and a store-name parameter on a per-object write path invites an IDOR.
    ['name' => 'migration#getWork',                  'url' => '/api/v1/migrations/{id}/work',            'verb' => 'GET'],
    ['name' => 'migration#reEncryptSecret',          'url' => '/api/v1/migrations/{id}/secrets/{secretId}', 'verb' => 'POST'],
    ['name' => 'migration#reEncryptVersion',         'url' => '/api/v1/migrations/{id}/versions/{versionId}', 'verb' => 'POST'],
    ['name' => 'migration#reEncryptAttachmentGrant', 'url' => '/api/v1/migrations/{id}/attachment-grants/{grantId}', 'verb' => 'POST'],

    // Key generator endpoint (stateless, authenticated).
    ['name' => 'keyGenerator#generate', 'url' => '/api/v1/generate-key', 'verb' => 'POST'],

    // Secret types CRUD (specific paths before the {id} secrets wildcard).
    ['name' => 'secretType#index',   'url' => '/api/v1/secret-types',      'verb' => 'GET'],
    ['name' => 'secretType#create',  'url' => '/api/v1/secret-types',      'verb' => 'POST'],
    ['name' => 'secretType#update',  'url' => '/api/v1/secret-types/{id}', 'verb' => 'PUT'],
    ['name' => 'secretType#destroy', 'url' => '/api/v1/secret-types/{id}', 'verb' => 'DELETE'],

    // Folder CRUD + children (children route before the {id} wildcard).
    ['name' => 'folder#index',    'url' => '/api/v1/folders',             'verb' => 'GET'],
    ['name' => 'folder#create',   'url' => '/api/v1/folders',             'verb' => 'POST'],
    ['name' => 'folder#children', 'url' => '/api/v1/folders/{id}/children', 'verb' => 'GET'],
    ['name' => 'folder#update',   'url' => '/api/v1/folders/{id}',        'verb' => 'PUT'],
    ['name' => 'folder#destroy',  'url' => '/api/v1/folders/{id}',        'verb' => 'DELETE'],

    // Secret CRUD. The nested link-shares route below is more specific and
    // is registered immediately after, so it still resolves correctly.
    ['name' => 'secret#index',   'url' => '/api/v1/secrets',      'verb' => 'GET'],
    ['name' => 'secret#create',  'url' => '/api/v1/secrets',      'verb' => 'POST'],
    // Batch import commit (secret-import D7). Accepts arrays of already
    // client-encrypted items; owner-scoped to the session user. The literal
    // /import-batch path is registered before the {id} wildcard so a POST
    // here never collides with the secret routes, and well before the SPA
    // catch-all wildcard.
    ['name' => 'import#batchCreate', 'url' => '/api/v1/secrets/import-batch', 'verb' => 'POST'],
    ['name' => 'secret#show',    'url' => '/api/v1/secrets/{id}', 'verb' => 'GET'],
    ['name' => 'secret#update',  'url' => '/api/v1/secrets/{id}', 'verb' => 'PUT'],
    ['name' => 'secret#destroy', 'url' => '/api/v1/secrets/{id}', 'verb' => 'DELETE'],

    // Link sharing — authenticated CRUD (secret owner).
    ['name' => 'linkShare#index',   'url' => '/api/v1/secrets/{secretId}/link-shares', 'verb' => 'GET'],
    ['name' => 'linkShare#create',  'url' => '/api/v1/secrets/{secretId}/link-shares', 'verb' => 'POST'],
    ['name' => 'linkShare#destroy', 'url' => '/api/v1/link-shares/{id}',                'verb' => 'DELETE'],

    // Link sharing — public access (no Nextcloud auth; two-phase protocol).
    ['name' => 'linkShareAccess#show',    'url' => '/api/v1/public/link-shares/{token}',         'verb' => 'GET'],
    ['name' => 'linkShareAccess#confirm', 'url' => '/api/v1/public/link-shares/{token}/confirm', 'verb' => 'POST'],

    // Emergency access — break-glass lifecycle (add-emergency-access §2.3).
    ['name' => 'emergencyAccess#index',              'url' => '/api/v1/emergency-access/contacts',                'verb' => 'GET'],
    ['name' => 'emergencyAccess#incoming',           'url' => '/api/v1/emergency-access/incoming',                'verb' => 'GET'],
    ['name' => 'emergencyAccess#granteeCertificate', 'url' => '/api/v1/emergency-access/grantee-certificate',     'verb' => 'GET'],
    ['name' => 'emergencyAccess#create',             'url' => '/api/v1/emergency-access/contacts',                'verb' => 'POST'],
    ['name' => 'emergencyAccess#destroy',            'url' => '/api/v1/emergency-access/contacts/{id}',           'verb' => 'DELETE'],
    ['name' => 'emergencyAccess#request',            'url' => '/api/v1/emergency-access/contacts/{id}/request',   'verb' => 'POST'],
    ['name' => 'emergencyAccess#decline',            'url' => '/api/v1/emergency-access/contacts/{id}/decline',   'verb' => 'POST'],
    ['name' => 'emergencyAccess#envelope',           'url' => '/api/v1/emergency-access/contacts/{id}/envelope',  'verb' => 'GET'],

    // User-to-user sharing — implement-user-sharing §9.
    ['name' => 'share#index',       'url' => '/api/v1/secrets/{secretId}/shares',       'verb' => 'GET'],
    ['name' => 'share#create',      'url' => '/api/v1/secrets/{secretId}/shares',       'verb' => 'POST'],
    ['name' => 'share#createBatch', 'url' => '/api/v1/secrets/{secretId}/shares/batch', 'verb' => 'POST'],
    // Bulk direct-share registration + recipient-cert lookup (bulk-actions §6.1).
    ['name' => 'share#registerBatch',        'url' => '/api/v1/shares/register-batch',        'verb' => 'POST'],
    ['name' => 'share#recipientCertificate', 'url' => '/api/v1/shares/recipient-certificate', 'verb' => 'GET'],
    ['name' => 'share#sync',        'url' => '/api/v1/secrets/{secretId}/sync',         'verb' => 'PUT'],
    ['name' => 'share#destroy',     'url' => '/api/v1/shares/{id}',                     'verb' => 'DELETE'],

    // Group sharing — implement-user-sharing §9.2.
    ['name' => 'groupShare#index',            'url' => '/api/v1/secrets/{secretId}/group-shares',           'verb' => 'GET'],
    ['name' => 'groupShare#create',           'url' => '/api/v1/secrets/{secretId}/group-shares',           'verb' => 'POST'],
    ['name' => 'groupShare#destroy',          'url' => '/api/v1/group-shares/{id}',                         'verb' => 'DELETE'],
    ['name' => 'groupShare#approveNewMember', 'url' => '/api/v1/group-shares/{id}/approve-new-member',      'verb' => 'POST'],
    ['name' => 'groupShare#denyNewMember',    'url' => '/api/v1/group-shares/{id}/deny-new-member',         'verb' => 'POST'],

    // Share requests — implement-user-sharing §9.3.
    ['name' => 'shareRequest#create',  'url' => '/api/v1/share-requests',           'verb' => 'POST'],
    ['name' => 'shareRequest#approve', 'url' => '/api/v1/share-requests/approve',   'verb' => 'POST'],
    ['name' => 'shareRequest#deny',    'url' => '/api/v1/share-requests/deny',      'verb' => 'POST'],

    // Delegations — implement-user-sharing §9.4.
    ['name' => 'delegation#index',   'url' => '/api/v1/secrets/{secretId}/delegations',         'verb' => 'GET'],
    ['name' => 'delegation#create',  'url' => '/api/v1/secrets/{secretId}/delegations',         'verb' => 'POST'],
    // The vault-admin power grab (user-sharing spec.md § Ownership Delegation).
    // Its own route, not a flag on delegation#create: the two are different
    // authorization decisions and must stay distinguishable in the audit trail.
    ['name' => 'delegation#handover', 'url' => '/api/v1/secrets/{secretId}/delegations/handover', 'verb' => 'POST'],
    // Group membership only, so the UI can decide whether to OFFER the
    // takeover. delegation#index answers only to a secret's owner, so a vault
    // admin cannot learn it from there.
    ['name' => 'delegation#capabilities', 'url' => '/api/v1/delegations/capabilities', 'verb' => 'GET'],
    ['name' => 'delegation#reclaim', 'url' => '/api/v1/secrets/{secretId}/delegations/reclaim', 'verb' => 'POST'],

    // Secret requests — scaffold (implement-secret-requests).
    ['name' => 'secretRequest#index',   'url' => '/api/v1/secret-requests',              'verb' => 'GET'],
    ['name' => 'secretRequest#listBySecret', 'url' => '/api/v1/secrets/{secretId}/requests', 'verb' => 'GET'],
    ['name' => 'secretRequest#create',  'url' => '/api/v1/secret-requests',              'verb' => 'POST'],
    ['name' => 'secretRequest#approve', 'url' => '/api/v1/secret-requests/{id}/approve', 'verb' => 'POST'],
    ['name' => 'secretRequest#decline', 'url' => '/api/v1/secret-requests/{id}/decline', 'verb' => 'POST'],
    ['name' => 'secretRequest#destroy', 'url' => '/api/v1/secret-requests/{id}',         'verb' => 'DELETE'],

    // Anonymous SPA shell for recipient pages (ephemeral-send §5.3 +
    // link-share access): serves the same index template as the app page
    // but as #[PublicPage], so account-less recipients reach the SPA.
    ['name' => 'publicShell#page', 'url' => '/public', 'verb' => 'GET'],

    // The public shell's SPA catch-all: the router runs createWebHistory
    // with base /apps/keepiq/public, so recipient links are PATHS
    // (/public/share/link/{token}, /public/send/{token},
    // /public/share/request/{token}) and a load or refresh of any of them
    // must serve the shell. Mirrors the AppHost dashboard#page +
    // dashboard#catchAll split: a distinct name, because Symfony silently
    // replaces same-named routes. Sits in $extra, so it precedes the
    // authenticated /{path} fallback.
    //
    // NEVER ROUTE AN AUTHENTICATED ENDPOINT UNDER /public/. This catch-all
    // ('path' => '.+') matches every GET below /public/ and hands it to a
    // #[PublicPage] controller, so a session-gated route placed here is
    // shadowed: the router matches this entry first, the anonymous shell
    // renders, and the intended handler never runs. The author sees a working
    // page, not a 404 — a routing slip becomes a silent auth bypass. The shell
    // itself exposes no data (it returns the HTML template and nothing else),
    // so there is no live vulnerability; the shape is the hazard.
    // tests/Unit/AppInfo/PublicRouteSurfaceContractTest.php enforces this:
    // every /public route must declare #[PublicPage], or the unit suite fails
    // and names the route.
    ['name' => 'publicShell#pageCatchAll', 'url' => '/public/{path}', 'verb' => 'GET',
        'requirements' => ['path' => '.+']],

    // Compliance reporting (compliance-reporting §4) — admin-only, the
    // gate runs in the controller body before any report logic.
    ['name' => 'complianceReport#generate', 'url' => '/api/v1/compliance/reports',               'verb' => 'POST'],
    ['name' => 'complianceReport#index',    'url' => '/api/v1/compliance/reports',               'verb' => 'GET'],
    ['name' => 'complianceReport#show',     'url' => '/api/v1/compliance/reports/{id}',          'verb' => 'GET'],
    ['name' => 'complianceReport#metrics',  'url' => '/api/v1/compliance/metrics',               'verb' => 'GET'],
    ['name' => 'complianceReport#exported', 'url' => '/api/v1/compliance/reports/{id}/exported', 'verb' => 'POST'],

    // SIEM audit export (siem-audit-export §6) — admin-only, the gate
    // runs in the controller body before any sink logic.
    ['name' => 'siemSink#index',   'url' => '/api/v1/siem/sinks',           'verb' => 'GET'],
    ['name' => 'siemSink#create',  'url' => '/api/v1/siem/sinks',           'verb' => 'POST'],
    ['name' => 'siemSink#update',  'url' => '/api/v1/siem/sinks/{id}',      'verb' => 'PUT'],
    ['name' => 'siemSink#destroy', 'url' => '/api/v1/siem/sinks/{id}',      'verb' => 'DELETE'],
    ['name' => 'siemSink#test',    'url' => '/api/v1/siem/sinks/{id}/test', 'verb' => 'POST'],

    // Certificate lifecycle (certificate-lifecycle §4) — inventory +
    // client-parsed metadata + guided renewal; owner-scoped in the
    // service. CA health rides the existing admin-only ca#getStatus.
    ['name' => 'certificate#inventory',        'url' => '/api/v1/certificates/inventory',                    'verb' => 'GET'],
    ['name' => 'certificate#submitMetadata',   'url' => '/api/v1/certificates/{secretId}/metadata',          'verb' => 'PUT'],
    ['name' => 'certificate#renewalChecklist', 'url' => '/api/v1/certificates/{secretId}/renewal-checklist', 'verb' => 'POST'],
    ['name' => 'certificate#reissueSuite',     'url' => '/api/v1/certificates/suites/{suiteId}/reissue',     'verb' => 'POST'],
    ['name' => 'cACertificate#health',         'url' => '/api/v1/ca/health',                                 'verb' => 'GET'],

    // Honey credentials (honey-credentials §4) — decoy tripwires;
    // owner/admin guards run in the service.
    ['name' => 'honey#flag',        'url' => '/api/v1/secrets/{id}/honey',           'verb' => 'POST'],
    ['name' => 'honey#unflag',      'url' => '/api/v1/secrets/{id}/honey',           'verb' => 'DELETE'],
    ['name' => 'honey#status',      'url' => '/api/v1/secrets/{id}/honey',           'verb' => 'GET'],
    ['name' => 'honey#alerts',      'url' => '/api/v1/honey/alerts',                 'verb' => 'GET'],
    ['name' => 'honey#acknowledge', 'url' => '/api/v1/honey/alerts/{id}/acknowledge', 'verb' => 'POST'],
    ['name' => 'honey#snooze',      'url' => '/api/v1/honey/alerts/{id}/snooze',     'verb' => 'POST'],

    // Offline cache (offline-readonly-cache §1.4) — owner-scoped
    // consolidated snapshot; 403 when the admin off switch is set.
    ['name' => 'offline#manifest', 'url' => '/api/v1/offline/manifest', 'verb' => 'GET'],

    // Offline service worker (offline-readonly-cache §3) — served from the
    // app root with the correct JS MIME + app-root default scope.
    ['name' => 'serviceWorker#script', 'url' => '/serviceworker.js', 'verb' => 'GET'],

    // PWA web app manifest (mobile-pwa §1.2) — served with the correct
    // application/manifest+json MIME; distinct from src/manifest.json.
    ['name' => 'webManifest#manifest', 'url' => '/manifest.webmanifest', 'verb' => 'GET'],

    // Passkey vault login (passkey-vault-login §2.4) — owner-scoped,
    // authenticated (the NC session is valid; only the vault is locked).
    ['name' => 'passkey#index',        'url' => '/api/v1/passkeys',               'verb' => 'GET'],
    ['name' => 'passkey#challenge',    'url' => '/api/v1/passkeys/challenge',     'verb' => 'GET'],
    ['name' => 'passkey#create',       'url' => '/api/v1/passkeys',               'verb' => 'POST'],
    ['name' => 'passkey#loginOptions', 'url' => '/api/v1/passkeys/login-options', 'verb' => 'GET'],
    ['name' => 'passkey#used',         'url' => '/api/v1/passkeys/{id}/used',     'verb' => 'POST'],
    ['name' => 'passkey#destroy',      'url' => '/api/v1/passkeys/{id}',          'verb' => 'DELETE'],

    // Ephemeral send (ephemeral-send §4): authenticated owner surface +
    // anonymous two-phase access (peek/access/confirm/failure).
    ['name' => 'ephemeralSend#create',  'url' => '/api/v1/sends',      'verb' => 'POST'],
    ['name' => 'ephemeralSend#index',   'url' => '/api/v1/sends',      'verb' => 'GET'],
    ['name' => 'ephemeralSend#destroy', 'url' => '/api/v1/sends/{id}', 'verb' => 'DELETE'],
    ['name' => 'ephemeralSendAccess#peek',    'url' => '/api/v1/public/sends/{token}',         'verb' => 'GET'],
    ['name' => 'ephemeralSendAccess#access',  'url' => '/api/v1/public/sends/{token}/access',  'verb' => 'POST'],
    ['name' => 'ephemeralSendAccess#confirm', 'url' => '/api/v1/public/sends/{token}/confirm', 'verb' => 'POST'],
    ['name' => 'ephemeralSendAccess#failure', 'url' => '/api/v1/public/sends/{token}/failure', 'verb' => 'POST'],

    // Secret requests — public fill-in flow (implement-secret-requests §4.2).
    // Token-based recipient endpoints; no Nextcloud auth.
    ['name' => 'secretRequestFill#show', 'url' => '/api/v1/public/secret-requests/{token}',      'verb' => 'GET'],
    ['name' => 'secretRequestFill#fill', 'url' => '/api/v1/public/secret-requests/{token}/fill', 'verb' => 'POST'],

    // Dashboard settings — scaffold (implement-dashboard-settings).
    ['name' => 'dashboardSettings#index',   'url' => '/api/v1/dashboard-settings',         'verb' => 'GET'],
    ['name' => 'dashboardSettings#show',    'url' => '/api/v1/dashboard-settings/{key}',   'verb' => 'GET'],
    ['name' => 'dashboardSettings#update',  'url' => '/api/v1/dashboard-settings',         'verb' => 'PUT'],
    ['name' => 'dashboardSettings#destroy', 'url' => '/api/v1/dashboard-settings',         'verb' => 'DELETE'],

    // Applications — scaffold (implement-application-mgmt). Specific
    // /pending route comes before the {id} wildcard.
    ['name' => 'application#pending', 'url' => '/api/v1/applications/pending',          'verb' => 'GET'],
    ['name' => 'application#index',   'url' => '/api/v1/applications',                  'verb' => 'GET'],
    ['name' => 'application#create',  'url' => '/api/v1/applications',                  'verb' => 'POST'],
    ['name' => 'application#approve', 'url' => '/api/v1/applications/{id}/approve',     'verb' => 'POST'],
    ['name' => 'application#reject',  'url' => '/api/v1/applications/{id}/reject',      'verb' => 'POST'],
    ['name' => 'application#certificate', 'url' => '/api/v1/applications/{id}/certificate', 'verb' => 'GET'],

    // An administrator's view of what an application is asking humans for
    // (admin-application-request-visibility). Admin-scoped, NOT registrar-scoped,
    // and distinct from /api/v1/app/secret-requests, which is the application's
    // own Bearer-authenticated surface over its own vault.
    ['name' => 'applicationRequestAdmin#index',   'url' => '/api/v1/applications/{id}/secret-requests', 'verb' => 'GET'],
    ['name' => 'applicationRequestAdmin#destroy', 'url' => '/api/v1/applications/{id}/secret-requests/{requestId}', 'verb' => 'DELETE'],

    ['name' => 'application#show',    'url' => '/api/v1/applications/{id}',              'verb' => 'GET'],
    ['name' => 'application#destroy', 'url' => '/api/v1/applications/{id}',              'verb' => 'DELETE'],

    // Machine secret-store API discovery document (public, no auth —
    // reveals only endpoint shapes; openconnector-secret-store-api §1.1).
    // The `doriath` segment survives the doriath -> keepiq rename on purpose:
    // it is a published contract URL, not an app id. See the class docblock
    // on DiscoveryController for the full reasoning.
    ['name' => 'discovery#document', 'url' => '/api/v1/app/.well-known/doriath', 'verb' => 'GET'],

    // JWT-Bearer token exchange (public; signature-verified).
    ['name' => 'applicationToken#exchange', 'url' => '/api/v1/token', 'verb' => 'POST'],

    // Bearer-authenticated application secrets API (openconnector-secret-store-api).
    // JwtAuthMiddleware enforces the Authorization header before the controller runs.
    // The by-name route precedes {id} so its extra path segment resolves first.
    ['name' => 'applicationSecrets#index',  'url' => '/api/v1/app/secrets',                 'verb' => 'GET'],
    ['name' => 'applicationSecrets#create', 'url' => '/api/v1/app/secrets',                 'verb' => 'POST'],
    ['name' => 'applicationSecrets#byName', 'url' => '/api/v1/app/secrets/by-name/{name}',  'verb' => 'GET',
        'requirements' => ['name' => '.+']],
    ['name' => 'applicationSecrets#show',   'url' => '/api/v1/app/secrets/{id}',            'verb' => 'GET'],
    ['name' => 'applicationSecrets#update', 'url' => '/api/v1/app/secrets/{id}',            'verb' => 'PUT'],

    // Machine leases (machine-secret-leases §4). Bearer-authenticated
    // application surface; JwtAuthMiddleware runs before each handler.
    // Machine secret-request creation (application-secret-request-creation
    // §1.1). Same JWT-Bearer posture as the sibling /api/v1/app/* routes: the
    // Application principal comes from JwtAuthMiddleware, never from a session.
    ['name' => 'applicationSecretRequests#index',  'url' => '/api/v1/app/secret-requests', 'verb' => 'GET'],
    ['name' => 'applicationSecretRequests#create', 'url' => '/api/v1/app/secret-requests', 'verb' => 'POST'],

    ['name' => 'machineLease#index',  'url' => '/api/v1/app/leases',              'verb' => 'GET'],
    ['name' => 'machineLease#renew',  'url' => '/api/v1/app/leases/{id}/renew',   'verb' => 'POST'],
    ['name' => 'machineLease#revoke', 'url' => '/api/v1/app/leases/{id}/revoke',  'verb' => 'POST'],
    // Session-authenticated admin/owner lease management.
    ['name' => 'leaseAdmin#index',     'url' => '/api/v1/applications/{id}/leases',       'verb' => 'GET'],
    ['name' => 'leaseAdmin#setPolicy', 'url' => '/api/v1/applications/{id}/lease-policy', 'verb' => 'PUT'],
    ['name' => 'leaseAdmin#revoke',    'url' => '/api/v1/leases/{leaseId}',               'verb' => 'DELETE'],

    // Version history (secret-version-history §6.2). List rides under the
    // secret; show/restore address the version id. Per-object authorization
    // lives in SecretVersionService method bodies.
    ['name' => 'secretVersion#index',   'url' => '/api/v1/secrets/{secretId}/versions',  'verb' => 'GET'],
    ['name' => 'secretVersion#show',    'url' => '/api/v1/versions/{id}',                'verb' => 'GET'],
    ['name' => 'secretVersion#restore', 'url' => '/api/v1/versions/{id}/restore',        'verb' => 'POST'],

    // Rotation & expiry (rotation-expiry-policies §5.1).
    ['name' => 'rotation#getExpiry',     'url' => '/api/v1/secrets/{id}/expiry',        'verb' => 'GET'],
    ['name' => 'rotation#setExpiry',     'url' => '/api/v1/secrets/{id}/expiry',        'verb' => 'PUT'],
    ['name' => 'rotation#policies',      'url' => '/api/v1/expiry-policies',            'verb' => 'GET'],
    ['name' => 'rotation#upsertPolicy',  'url' => '/api/v1/expiry-policies',            'verb' => 'POST'],
    ['name' => 'rotation#destroyPolicy', 'url' => '/api/v1/expiry-policies/{id}',       'verb' => 'DELETE'],
    ['name' => 'rotation#flags',         'url' => '/api/v1/rotation-flags',             'verb' => 'GET'],
    ['name' => 'rotation#flagBatch',     'url' => '/api/v1/rotation-flags',             'verb' => 'POST'],
    ['name' => 'rotation#markRotated',   'url' => '/api/v1/rotation-flags/{id}/rotated', 'verb' => 'POST'],
    ['name' => 'rotation#dismissFlag',   'url' => '/api/v1/rotation-flags/{id}/dismiss', 'verb' => 'POST'],

    // Attachments (encrypted-attachments §4.2). Upload/list ride under the
    // owning secret; download/grants/delete address the attachment id.
    // Per-object authorization lives in AttachmentService method bodies.
    ['name' => 'attachment#create',   'url' => '/api/v1/secrets/{secretId}/attachments', 'verb' => 'POST'],
    ['name' => 'attachment#index',    'url' => '/api/v1/secrets/{secretId}/attachments', 'verb' => 'GET'],
    ['name' => 'attachment#download', 'url' => '/api/v1/attachments/{id}/blob',          'verb' => 'GET'],
    ['name' => 'attachment#addGrant', 'url' => '/api/v1/attachments/{id}/grants',        'verb' => 'POST'],
    ['name' => 'attachment#destroy',  'url' => '/api/v1/attachments/{id}',               'verb' => 'DELETE'],

    // Team folder sharing (team-folder-sharing §4.1). Static segments
    // (offboard) precede the {id} routes so they resolve first. Per-object
    // owner/admin guards live in TeamFolderService method bodies.
    //
    // The membership routes target TeamFolderMemberController; the folder
    // routes target TeamFolderController. The URLs are identical to before
    // that split — only the route names differ — so no client changes.
    ['name' => 'teamFolder#index',                'url' => '/api/v1/team-folders',                         'verb' => 'GET'],
    ['name' => 'teamFolder#create',               'url' => '/api/v1/team-folders',                         'verb' => 'POST'],
    ['name' => 'teamFolder#offboard',             'url' => '/api/v1/team-folders/offboard',                'verb' => 'POST'],
    ['name' => 'teamFolderMember#members',        'url' => '/api/v1/team-folders/{id}/members',            'verb' => 'GET'],
    ['name' => 'teamFolderMember#addMember',      'url' => '/api/v1/team-folders/{id}/members',            'verb' => 'POST'],
    ['name' => 'teamFolderMember#removeMember',   'url' => '/api/v1/team-folders/{id}/members/{memberId}', 'verb' => 'DELETE'],
    // Folder permission grades (folder-permission-grades §3.1).
    ['name' => 'teamFolderMember#setMemberGrade', 'url' => '/api/v1/team-folders/{id}/members/{memberId}', 'verb' => 'PATCH'],
    ['name' => 'share#writeContext',              'url' => '/api/v1/secrets/{id}/write-context',           'verb' => 'GET'],
    ['name' => 'teamFolder#reconcile',            'url' => '/api/v1/team-folders/{id}/reconcile',          'verb' => 'GET'],
    ['name' => 'teamFolder#registerShares',       'url' => '/api/v1/team-folders/{id}/shares',             'verb' => 'POST'],
    ['name' => 'teamFolderMember#approveJoin',    'url' => '/api/v1/team-folders/{id}/approve-join',       'verb' => 'POST'],
    ['name' => 'teamFolder#destroy',              'url' => '/api/v1/team-folders/{id}',                    'verb' => 'DELETE'],

    // Audit trail (add-secret-audit-trail §4.1). Specific /secret/{id} and
    // /me routes come before the admin instance-wide /audit collection.
    ['name' => 'audit#secret', 'url' => '/api/v1/audit/secret/{id}', 'verb' => 'GET'],
    ['name' => 'audit#mine',   'url' => '/api/v1/audit/me',          'verb' => 'GET'],
    ['name' => 'audit#index',  'url' => '/api/v1/audit',             'verb' => 'GET'],

    // Password-health breach-check proxy (password-health §1.5). Prefix-only
    // k-anonymity forward to HIBP; double-gated (admin setting + user opt-in).
    ['name' => 'breachProxy#range', 'url' => '/api/v1/breach-check/range/{prefix}', 'verb' => 'GET'],

    // GDPR data-subject endpoints (secret-export-gdpr D3/D4). All self-scoped
    // to the session user — no user selector. Master-password re-auth on the
    // delete is enforced client-side (ADR-003); the typed confirmation phrase
    // is the server-checkable gate.
    ['name' => 'gdpr#metadata',          'url' => '/api/v1/gdpr/metadata',     'verb' => 'GET'],
    ['name' => 'gdpr#deleteAccountData', 'url' => '/api/v1/gdpr/account-data', 'verb' => 'DELETE'],

    // Export-event emission (secret-export-gdpr D5). The client reports a
    // completed export before offering the local download; this dispatches
    // SecretExportedEvent for the session user. No secret material is sent.
    ['name' => 'export#events', 'url' => '/api/v1/export/events', 'verb' => 'POST'],

    // CXP (FIDO Credential Exchange Protocol) opaque handshake relay
    // (cxp-transfer §2.2). Carries only public keys + HPKE ciphertext between
    // two cooperating browser sessions — never plaintext or server-openable keys.
    ['name' => 'cxpRelay#put', 'url' => '/api/v1/cxp/relay', 'verb' => 'POST'],
    ['name' => 'cxpRelay#get', 'url' => '/api/v1/cxp/relay/{pairingId}/{slot}', 'verb' => 'GET'],

    // Browser-extension pairing + URL match (browser-extension-autofill §1).
    // App-password authenticated; returns encrypted blobs only, never plaintext.
    ['name' => 'extension#pair', 'url' => '/api/v1/extension/pair', 'verb' => 'POST'],
    ['name' => 'extension#unpair', 'url' => '/api/v1/extension/unpair', 'verb' => 'POST'],
    ['name' => 'extension#match', 'url' => '/api/v1/extension/match', 'verb' => 'GET'],
]);
