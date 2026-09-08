# apphost-adoption Specification

## Purpose
TBD - created by archiving change adopt-apphost. Update Purpose after archive.
## Requirements
### Requirement: Public Declarative Health Endpoint

Keepiq SHALL serve `GET /apps/keepiq/api/health` through the AppHost `GenericHealthController` from manifest descriptors (`database` critical, `filesystem` degraded), publicly accessible per ADR-006, and the response SHALL contain no secret material.

#### Scenario: Anonymous health check succeeds

- **GIVEN** a healthy instance with keepiq enabled
- **WHEN** `GET /apps/keepiq/api/health` is called with no authentication
- **THEN** the response MUST be HTTP 200 with `status = "ok"`, `checks.database = "ok"`, and `checks.filesystem = "ok"`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Health response carries no secret material

- **GIVEN** an instance containing secrets, encryption suites, shares, and link-share tokens
- **WHEN** `GET /apps/keepiq/api/health` is called with no authentication
- **THEN** the response body MUST contain ONLY the keys `status`, `app`, `version`, and `checks`, and the `checks` values MUST be limited to `"ok"` or `"failed: <infrastructure error>"` strings — no secret values, suite identifiers, key material, share tokens, user identifiers, or row counts
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Degraded filesystem does not mask database health

- **GIVEN** an instance where the temp filesystem is not writable but the database is reachable
- **WHEN** `GET /apps/keepiq/api/health` is called
- **THEN** the response MUST report `status = "degraded"` with `checks.filesystem` beginning `failed:` and `checks.database = "ok"`, per the `severity: degraded` descriptor
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Admin-Only Declarative Metrics Endpoint

Keepiq SHALL serve `GET /apps/keepiq/api/metrics` through the AppHost `GenericMetricsController` in Prometheus text exposition format 0.0.4, restricted to admin users per ADR-006, with `keepiq_suites_total` computed as a SQL `COUNT(*)` over `keepiq_enc_suites` filtered to `status = 'active'`.

#### Scenario: Metrics parity after adoption

- **GIVEN** a seeded instance with N active and M revoked encryption suites
- **WHEN** `GET /apps/keepiq/api/metrics` is called by an admin
- **THEN** the output MUST contain `keepiq_info{version,php_version,nextcloud_version}`, `keepiq_up 1`, and `keepiq_suites_total N` (active suites only, matching a direct filtered table count), as `Content-Type: text/plain; version=0.0.4`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Non-admin user cannot scrape metrics

- **GIVEN** an authenticated non-admin user
- **WHEN** that user calls `GET /apps/keepiq/api/metrics`
- **THEN** the request MUST be rejected (HTTP 403), tightening the pre-adoption any-authenticated-user posture to ADR-006 admin-only
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Boilerplate Plumbing Served by AppHost Generics

Keepiq SHALL delete its local `HealthController`, `MetricsController`, and `DeepLinkRegistrationListener`, and SHALL shrink `AdminSettings` and `SettingsSection` to one-line engine-backed subclasses (they must physically exist for NC class-name instantiation via info.xml and as the `#[AuthorizedAdminSetting(AdminSettings::class)]` target — the AppHost stub floor), wiring their responsibilities through `AppHost\Bootstrap::register()` and `AppHost\Routes::standard()`, with all route names, URLs, and public-route postures unchanged for domain endpoints. `InitializeSettings` SHALL be retained bespoke (it seeds domain default-config keys the generic repair step does not) and re-registered after `Bootstrap::register()` so the concrete wins over the generic alias.

#### Scenario: Domain routes unchanged after Routes::standard adoption

- **GIVEN** the adopted `appinfo/routes.php` built from `Routes::standard($extra)`
- **WHEN** the route table is compared with the pre-adoption baseline
- **THEN** every domain route (secrets, suites, CA, shares, delegations, public link-share access, public secret-request fill, JWT token exchange, applications, dashboard settings) MUST resolve to the same controller method at the same URL and verb, and the SPA catch-all MUST remain the last route
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Register configuration imported by the retained repair step

- **GIVEN** a fresh install with the bespoke `lib/Repair/InitializeSettings.php` retained (it seeds domain default-config keys) and re-registered after `Bootstrap::register()`
- **WHEN** `occ app:enable keepiq` runs and repair steps execute
- **THEN** the register configuration from `keepiq_register.json` (plus `register.d/` fragments) MUST be imported and the app's register/schema config keys resolved, identical to the pre-adoption repair step, and the domain default-config keys MUST be seeded
- @e2e exclude install-time repair step — verified via occ in CI, no UI surface

#### Scenario: Admin settings page still renders through the generic section

- **GIVEN** the local `AdminSettings` and `SettingsSection` are deleted and Bootstrap registers the AppHost generics
- **WHEN** an admin opens the Keepiq section in Nextcloud admin settings
- **THEN** the settings form MUST render and the domain admin settings (password policy, session timeout, CA auto-renew) MUST remain readable and writable via the retained `SettingsController` subclass methods

### Requirement: Domain Surfaces Excluded From Adoption

Keepiq SHALL retain its `DashboardController` (argon2-WASM CSP and `summary()` aggregation), `DashboardService`, and every controller, service, middleware, listener, and repair step that touches secrets, encryption suites, shares, certificates, or JWT authentication, unchanged by this adoption.

#### Scenario: Dashboard WASM CSP survives adoption

- **GIVEN** the adopted app with AppHost wiring in place
- **WHEN** a user opens the Keepiq SPA and accesses a link share whose AES key derives via the argon2-browser WASM module
- **THEN** the page response MUST still carry the `wasm-unsafe-eval` CSP opt-in and the client-side key derivation MUST succeed

