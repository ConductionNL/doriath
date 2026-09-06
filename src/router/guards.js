/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Vault navigation guard.
 *
 * The encryption-suites spec requires that when the in-memory CryptoKey is
 * absent, "any Keepiq route" access redirects to the lock screen, and that
 * the lock screen is a full page rather than an overlay. Enforcing that from
 * a component lifecycle hook is too late: the router has already resolved
 * the route, so `CnPageRenderer` mounts the target page and its `mounted()`
 * fires before any redirect can land. `SecretList` in particular issues
 * `fetchSecrets()` unconditionally on mount, and secret `name` / `url` /
 * folder placement are stored server-side in plaintext by design (see
 * lib/Db/Secret.php) so the vault inventory paints before the lock screen
 * replaces it.
 *
 * This guard runs in `beforeEach`, so a locked vault never resolves a
 * protected route at all — no page component is instantiated and no fetch
 * is issued. It is deliberately synchronous: gating navigation behind an
 * awaited settings request (as the previous App.vue boot redirect did)
 * reopens exactly the window it is meant to close.
 *
 * WHAT THIS IS AND IS NOT.
 *
 * This is a UI gate, not an authorisation check. Secret `name`, `url` and
 * `folderId` are stored server-side in plaintext by design (searchable for
 * their owner) and `SecretController::index()` authorises on the Nextcloud
 * session alone. There is no server-side notion of "locked" — the master
 * password never leaves the browser and `isLocked` is purely
 * `cryptoKey === null` — so `curl` with a valid session cookie, or a
 * Nextcloud app password, returns the full inventory whether or not any tab
 * has the vault unlocked.
 *
 * What this guard removes is the browser rendering and fetching that
 * inventory: the restored-tab and deep-link cases, where the app itself put
 * the vault on screen before the lock screen replaced it. It adds nothing
 * against a shared session, a stolen cookie or a hostile extension. Gating
 * inventory reads on the master password would need a separate server-side
 * control — e.g. binding them to a short-lived token minted at unlock — and
 * that is not what this is.
 *
 * vue-router 4 note: the `(to, from, next)` signature is still fully
 * supported. It is kept over the newer "return a route location" idiom so
 * the redirect and the pass-through read identically.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */

/**
 * Name of the lock-screen route (manifest page id `Lock`, path `/lock`).
 *
 * @type {string}
 */
export const LOCK_ROUTE_NAME = 'Lock'

/**
 * Route names reachable without an unlocked vault.
 *
 * These are the recipient-facing token URLs. A link-share, secret-request,
 * or ephemeral-send recipient is frequently not the vault owner and may
 * have no Keepiq suite at all, so gating them on a master password would
 * make the sharing features unusable rather than more secure. They carry
 * their own token-scoped authorisation server-side.
 *
 * @type {string[]}
 * @spec openspec/changes/implement-secret-requests/tasks.md#task-9.2
 */
export const PUBLIC_ROUTE_NAMES = [
	'SecretRequestFill',
	'LinkShareAccess',
	'EphemeralSendAccess',
]

/**
 * Whether a vue-router route lives outside the locked-vault guard.
 *
 * @param {object|null} route The route object to test.
 * @return {boolean} True when the route needs no unlocked vault.
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */
export function isPublicRoute(route) {
	if (route == null) {
		return false
	}
	// Allow either explicit per-route `meta.public:true` (when the shared
	// manifest schema ships that field) or membership in the
	// PUBLIC_ROUTE_NAMES allow-list maintained alongside this module.
	if (route.meta && route.meta.public === true) {
		return true
	}
	return PUBLIC_ROUTE_NAMES.includes(route.name)
}

/**
 * Path prefixes of the recipient-facing routes, relative to the router base.
 *
 * Derived from the same routes as PUBLIC_ROUTE_NAMES, kept as paths because the
 * check below runs before the router has resolved a name.
 *
 * @type {string[]}
 */
export const PUBLIC_PATH_PREFIXES = ['/share/request/', '/share/link/', '/send/']

/**
 * Hash prefixes of the same routes under the RETIRED hash-routing scheme.
 *
 * Links built under that scheme are still in the wild (they were mailed to
 * recipients), and `applyHashRouteHandoff()` in the bootstrap rewrites them to
 * the path form before the router is created. This list exists for the reads
 * that can happen before or without that handoff — direct unit-test calls,
 * mainly — so the classification of a legacy URL never depends on rewrite
 * order.
 *
 * @type {string[]}
 */
export const PUBLIC_HASH_PREFIXES = ['#/share/request/', '#/share/link/', '#/send/']

/**
 * The app's own path segment, as it appears in every Keepiq URL.
 *
 * @type {string}
 */
const APP_PATH_SEGMENT = '/apps/keepiq'

/**
 * Path of the anonymous SPA shell, relative to the app's path segment.
 *
 * @type {string}
 */
const PUBLIC_SHELL_PATH = '/public'

/**
 * The part of a pathname that follows the app's own path segment.
 *
 * Nextcloud can be served from a sub-directory and with or without
 * `/index.php`, so the app segment is located rather than anchored: the base
 * may be `/apps/keepiq`, `/index.php/apps/keepiq` or
 * `/nextcloud/index.php/apps/keepiq`. What it may NOT be is a longer segment
 * that merely starts with the app id — the remainder has to begin at a `/`
 * boundary (or be empty), so `/apps/keepiq-old/...` and `/apps/keepiqfoo` are
 * not this app.
 *
 * @param {string} path The pathname to split.
 * @return {string|null} The remainder, or null when the path is not this app's.
 */
function appRelativePath(path) {
	const at = path.indexOf(APP_PATH_SEGMENT)
	if (at === -1) {
		return null
	}

	const rest = path.slice(at + APP_PATH_SEGMENT.length)
	if (rest !== '' && rest.startsWith('/') === false) {
		return null
	}

	return rest
}

/**
 * Whether the CURRENT URL has the shape of an anonymous recipient surface.
 *
 * NOT the security gate. Access to a recipient page is authorised server-side
 * by its own scoped token, and the locked-vault gate is the name-based
 * `isPublicRoute()` above; this only classifies a URL so the shell can decide
 * what chrome to render. It is loaded here for a reason `isPublicRoute()`
 * cannot serve: that one needs a resolved route, and some decisions are read
 * before the router's first navigation completes — CnAppRoot, for one, reads
 * `supportDialog` once in `setup()`. This answers the same question from the
 * URL alone, so it is correct at that moment.
 *
 * `/apps/keepiq/public` is the anonymous SPA shell, so anything served from it
 * is a recipient page by definition; the path prefixes cover the same routes
 * when reached on the authenticated shell, and the legacy hash prefixes cover
 * links built under the retired hash-routing scheme.
 *
 * Every comparison is boundary-aware. A substring test on
 * `'/apps/keepiq/public'` also matched `/apps/keepiq/publications/…` and
 * `/apps/keepiq/publicfoo` — no exploit, since this is not the gate, but a
 * classifier whose answer is wrong for a whole family of paths invites being
 * mistaken for one.
 *
 * @param {{pathname?: string, hash?: string}} location A Location-like object.
 * @return {boolean} True when the URL is a public recipient surface.
 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
 */
export function isPublicSurface(location = {}) {
	const path = location.pathname || ''
	const hash = location.hash || ''
	const rest = appRelativePath(path)

	if (rest === null) {
		return false
	}

	if (rest === PUBLIC_SHELL_PATH || rest.startsWith(`${PUBLIC_SHELL_PATH}/`)) {
		return true
	}

	if (PUBLIC_PATH_PREFIXES.some((prefix) => rest.startsWith(prefix))) {
		return true
	}

	return PUBLIC_HASH_PREFIXES.some((prefix) => hash.startsWith(prefix))
}

/**
 * Redirect to the lock screen when the vault locks mid-session.
 *
 * `beforeEach` only fires on navigation, so it cannot see the vault locking
 * while the user sits still on an already-resolved route — a session timeout
 * or the "Lock vault" menu entry. That path is this function's; the guard
 * owns the entry path.
 *
 * Extracted from App.vue's `isLocked` watcher so the decision is testable
 * without mounting the shell. The bug this whole change fixes was a
 * lifecycle-driven redirect that worked in the eventual state and was
 * therefore never caught, and this is the same shape — so it is asserted
 * rather than described.
 *
 * Fail closed on the same terms as createVaultGuard: ONLY an explicit `false`
 * counts as unlocked. Read as `if (locked !== true) return false` this would
 * do NOTHING when `locked` is absent or non-boolean, leaving the user sitting
 * on a rendered secret page — and `beforeEach` cannot rescue them, because the
 * no-navigation case is precisely what this function exists for. In the
 * shipped wiring `isLocked` is `cryptoKey === null` and always boolean, so
 * this is hardening rather than a live defect; the point is that the two
 * halves of one invariant must not disagree about which way is safe.
 *
 * @param {boolean} locked Whether the vault is now locked.
 * @param {object|null} route The current route ($route).
 * @param {object} router The router ($router), needing `.replace()`.
 * @return {boolean} True when a redirect was issued.
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */
export function handleLockTransition(locked, route, router) {
	if (locked === false) {
		return false
	}

	// Already on the lock screen, or on a recipient-facing token route that
	// never required an unlocked vault.
	if (route?.name === LOCK_ROUTE_NAME || isPublicRoute(route)) {
		return false
	}

	router.replace({
		name: LOCK_ROUTE_NAME,
		query: { returnUrl: route?.fullPath },
	})

	return true
}

/**
 * Build the `beforeEach` guard that keeps every application screen behind
 * the master password.
 *
 * The session store is injected as a factory rather than imported directly
 * so the guard can be constructed before the Pinia instance is installed on
 * the app, and so unit tests can drive it without Pinia.
 *
 * @param {Function} getSessionStore Returns the session store (with `isLocked`).
 * @return {Function} A vue-router `beforeEach(to, from, next)` guard.
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */
export function createVaultGuard(getSessionStore) {
	return function vaultGuard(to, from, next) {
		// The lock screen itself must always resolve, otherwise the
		// redirect below would recurse.
		if (to.name === LOCK_ROUTE_NAME || isPublicRoute(to)) {
			next()
			return
		}

		// Fail closed: ONLY an explicit `false` is treated as unlocked. Read as
		// `if (isLocked)` this would allow navigation whenever the property is
		// absent or non-boolean, which puts the safe default in the store's
		// hands rather than the guard's. In the shipped wiring `isLocked` is
		// always a boolean starting `true`, so this is hardening, not a live
		// defect — but the guard should not depend on that to be safe.
		//
		// Deliberately NO try/catch: if the store factory throws, the
		// exception propagates and vue-router aborts the navigation, which is
		// the fail-closed outcome. Wrapping it would turn a hard failure into
		// a silent allow.
		const store = getSessionStore()

		if (store?.isLocked !== false) {
			next({
				name: LOCK_ROUTE_NAME,
				query: { returnUrl: to.fullPath },
			})
			return
		}

		next()
	}
}

/**
 * The manifest as the SHELL should see it for the current lock state.
 *
 * 🔴 THE VAULT GUARD ABOVE DOES NOT COVER THIS. That guard works by refusing
 * to resolve a route, so it only reaches what mounts inside the
 * `<router-view>`. The walkthrough is a shell-level SIBLING of it: CnAppRoot
 * reads `manifest.walkthrough` and fetches the tour's completion preference
 * when the shell mounts, before any route resolves. That put
 * `GET /api/preferences/walkthrough_completed_version` on the wire behind the
 * lock screen and broke the invariant that a locked vault issues no Keepiq API
 * request at all — the assertion that exists because a lock screen which is a
 * redirect rather than a gate leaks the real inventory.
 *
 * Withheld, not disabled: the shell re-renders when `isLocked` flips, so the
 * tour is offered on the first UNLOCKED visit. A product tour drawn over a
 * locked vault would be the wrong behaviour regardless of the request.
 *
 * Fail closed, exactly as `createVaultGuard` does: only an explicit `false`
 * counts as unlocked, so a store that failed to initialise withholds the tour
 * rather than shipping it.
 *
 * @param {object} manifest The bundled manifest.
 * @param {object} store    The session store (with `isLocked`).
 * @return {object} The manifest, without `walkthrough` while locked.
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */
export function manifestForLockState(manifest, store) {
	if (store?.isLocked === false) {
		return manifest
	}

	if (
		manifest === null
		|| manifest === undefined
		|| manifest.walkthrough === undefined
	) {
		return manifest
	}

	const withoutWalkthrough = { ...manifest }
	delete withoutWalkthrough.walkthrough

	return withoutWalkthrough
}
