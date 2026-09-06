/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Hash-route → path-route handoff at bootstrap, plus the router-base match.
 *
 * The router moved from `createWebHashHistory` to `createWebHistory`, but
 * links built under the old scheme are still in the wild — most damagingly
 * the recipient links (`/apps/keepiq/public#/share/link/<token>`) that were
 * handed to people OUTSIDE the app, in emails and messages, with no way to
 * regenerate them. Under path routing the fragment is never read: vue-router
 * sees the bare `/public` path, the catch-all redirects to `/`, the vault
 * guard bounces to the lock screen, and a recipient with no Nextcloud
 * account is asked for a master password they do not have.
 *
 * This module rewrites such a URL IN PLACE (history.replaceState — no
 * request, no reload) before the router is created, so the old links resolve
 * exactly like the path links the builders now emit.
 *
 * ⚠️ The ephemeral-send content key must never reach the server. Legacy send
 * links carry it INSIDE the fragment (`#/send/<token>?k=<key>`), where it is
 * invisible to the server by construction. The handoff therefore moves `k`
 * into a NEW fragment (`/send/<token>#k=<key>`) rather than into the real
 * query string — a real `?k=` would be transmitted (and logged) on the next
 * refresh of the rewritten URL. Every other hash-route query parameter is
 * ordinary UI state (`?action=create`) and becomes a real query parameter.
 */

/**
 * The vue-router base for a given pathname, or null when the pathname is not
 * a Keepiq URL.
 *
 * ⚠️ `generateUrl('/apps/keepiq')` alone is not enough (the caller falls back
 * to it). Nextcloud serves the app under BOTH `/apps/keepiq/...` and
 * `/index.php/apps/keepiq/...`, but `generateUrl()` returns only the form the
 * instance is configured for. A visitor arriving on the other form — a
 * bookmark, an emailed deep link, an integration that hardcodes `/index.php`
 * — falls outside the router base, vue-router cannot resolve the path, and
 * the catch-all redirects to `/`. They land on the dashboard with no error:
 * the deep link is silently swallowed.
 *
 * The anonymous shell extends the base by `/public`: recipient links resolve
 * as `/apps/keepiq/public/share/link/<token>`, so on that shell the base is
 * `/apps/keepiq/public` and the SAME manifest routes (`/share/link/:token`)
 * match on both shells. The optional group backtracks correctly for a
 * hypothetical `/apps/keepiq/publication` route — `(?:\/|$)` refuses the
 * partial word and the base stays `/apps/keepiq`.
 *
 * @param {string} pathname A Location pathname.
 * @return {string|null} The base path vue-router should strip, or null.
 */
export function matchRouterBase(pathname) {
	const match = String(pathname || '').match(
		/^(.*\/apps\/keepiq(?:\/public)?)(?:\/|$)/,
	)
	return match ? match[1] : null
}

/**
 * The rewritten URL for a legacy hash-routed location, or null when the
 * location needs no handoff.
 *
 * `/apps/keepiq/public#/share/link/t` → `/apps/keepiq/public/share/link/t`
 * `/apps/keepiq/public#/send/t?k=K`   → `/apps/keepiq/public/send/t#k=K`
 * `/apps/keepiq/#/secrets`            → `/apps/keepiq/secrets`
 *
 * Only fragments that LOOK like a hash route (`#/...`) are touched: a plain
 * anchor or a `#k=` fragment produced by the new send links must pass through
 * untouched, otherwise the handoff would eat the very key it exists to
 * protect.
 *
 * @param {{pathname?: string, hash?: string, search?: string}} location A Location-like object.
 * @return {string|null} The relative URL to replaceState to, or null.
 */
export function hashRouteHandoffTarget(location = {}) {
	const hash = location.hash || ''
	if (!hash.startsWith('#/')) {
		return null
	}

	const base = matchRouterBase(location.pathname)
	if (base === null) {
		return null
	}

	const raw = hash.slice(1)
	const queryIndex = raw.indexOf('?')
	const routePath = queryIndex === -1 ? raw : raw.slice(0, queryIndex)
	const routeQuery = queryIndex === -1 ? '' : raw.slice(queryIndex + 1)

	// Merge the (normally empty) real query with the hash route's own query.
	const params = new URLSearchParams(location.search || '')
	for (const [name, value] of new URLSearchParams(routeQuery)) {
		params.set(name, value)
	}

	// The content key stays in the fragment — see the module docblock.
	let fragment = ''
	const contentKey = params.get('k')
	if (contentKey !== null) {
		params.delete('k')
		fragment = `#k=${encodeURIComponent(contentKey)}`
	}

	const query = params.toString()
	return base + routePath + (query ? `?${query}` : '') + fragment
}

/**
 * Rewrite the current URL when it carries a legacy hash route.
 *
 * Must run BEFORE `createRouter()`: `createWebHistory` reads the location at
 * construction, so a handoff after that would leave the router resolved on
 * the dead `/public` path with the fragment already ignored.
 *
 * replaceState (not pushState): the hash form should not survive in history,
 * or Back would bounce between the two spellings of the same page.
 *
 * @param {Window} win The window to operate on (injectable for tests).
 * @return {boolean} True when the URL was rewritten.
 */
export function applyHashRouteHandoff(win = window) {
	const target = hashRouteHandoffTarget(win.location)
	if (target === null) {
		return false
	}

	win.history.replaceState(win.history.state, '', target)
	return true
}
