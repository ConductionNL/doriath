/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for the vault navigation guard.
 *
 * The regression these lock down: the lock screen used to be reached via an
 * App.vue `created()` redirect that ran after an awaited settings request, so
 * the target page mounted and fetched before the redirect landed. The guard
 * must reject the navigation itself, for every application route.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */

import { describe, expect, it, vi } from 'vitest'
import manifest from '../../src/manifest.json'
import {
	createVaultGuard,
	handleLockTransition,
	isPublicRoute,
	isPublicSurface,
	LOCK_ROUTE_NAME,
	manifestForLockState,
	PUBLIC_ROUTE_NAMES,
} from '../../src/router/guards.js'

/**
 * Build a guard plus a spy `next`, over a session store of the given state.
 *
 * @param {boolean} isLocked Whether the vault is locked.
 * @return {{guard: Function, next: Function}} Guard under test and its next spy.
 */
function harness(isLocked) {
	const next = vi.fn()
	const guard = createVaultGuard(() => ({ isLocked }))
	return { guard, next }
}

/**
 * Minimal vue-router route stub.
 *
 * @param {string} name Route name.
 * @param {string} fullPath Resolved path.
 * @param {object} meta Route meta.
 * @return {object} Route-like object.
 */
function route(name, fullPath = `/${name.toLowerCase()}`, meta = {}) {
	return { name, fullPath, meta }
}

// Every non-public application screen in src/manifest.json. Listed
// explicitly rather than imported so that adding a page to the manifest
// without considering the guard shows up as a deliberate edit here.
// The 'stays in step with src/manifest.json' test below is what makes
// that true — without it this comment would describe a drift check that
// does not exist.
//
// Scope: the BUNDLED manifest only. main.js builds the router from
// buildManifest(bundledManifest, fragments, menuLayout), where fragments is
// require.context('./manifest.d/', ...) — a webpack-only API with no vitest
// equivalent, so a page delivered as a fragment gets a route that this check
// cannot see. That is inert today (src/manifest.d/ ships only an empty
// placeholder) and the guard denies unknown routes structurally, so such a
// page would be gated, just untested. Revisit if manifest.d/ ever carries
// real pages.
const PROTECTED_ROUTES = [
	'Dashboard',
	'FeaturesRoadmap',
	'PersonalActivity',
	'PasswordHealth',
	// Reports is PROTECTED, not public. It is the page those two now live on
	// as cards (ADR-112 / ADR-114), and both read vault state: Password health
	// analyses the UNLOCKED vault in memory, and My activity is the caller's
	// own audit trail. A Reports page reachable while the vault is locked would
	// be a way in around the lock, even if each card then refused.
	'Reports',
	'Certificates',
	'EmergencyAccess',
	// SecretList/'SecretListFolder' also carry the optional `:id?` detail
	// segment (restyle Stage 8) — the old SecretDetail page id is gone, but
	// /secrets/<id> deep links still resolve to SecretList and stay gated.
	'SecretList',
	'SecretListFolder',
	'ApplicationRegister',
	'ApplicationDetail',
	// Flows are PROTECTED, not public. A flow in this app can read and write
	// vault secrets, so the authoring surface must sit behind the lock exactly
	// as the secret list does — a locked vault that still lets someone edit the
	// automation over it is not locked.
	'Flows',
	'FlowDetail',
]

describe('createVaultGuard', () => {
	describe('when the vault is locked', () => {
		it.each(PROTECTED_ROUTES)('redirects %s to the lock screen', (name) => {
			const { guard, next } = harness(true)

			guard(route(name), route('Dashboard', '/'), next)

			expect(next).toHaveBeenCalledTimes(1)
			expect(next).toHaveBeenCalledWith({
				name: LOCK_ROUTE_NAME,
				query: { returnUrl: `/${name.toLowerCase()}` },
			})
		})

		it('preserves the attempted path as returnUrl so unlock resumes it', () => {
			const { guard, next } = harness(true)

			// A secret deep link resolves to the SecretList route with the
			// optional :id segment (restyle Stage 8) — the deep PATH is what
			// must survive as returnUrl.
			guard(route('SecretList', '/secrets/42'), route('Dashboard', '/'), next)

			expect(next).toHaveBeenCalledWith({
				name: LOCK_ROUTE_NAME,
				query: { returnUrl: '/secrets/42' },
			})
		})

		it('lets the lock screen itself resolve, so the redirect cannot recurse', () => {
			const { guard, next } = harness(true)

			guard(route(LOCK_ROUTE_NAME, '/lock'), route('Dashboard', '/'), next)

			expect(next).toHaveBeenCalledWith()
		})

		it.each(PUBLIC_ROUTE_NAMES)(
			'lets the recipient-facing route %s through',
			(name) => {
				const { guard, next } = harness(true)

				guard(
					route(name, `/share/${name}/tok`),
					route('Dashboard', '/'),
					next,
				)

				expect(next).toHaveBeenCalledWith()
			},
		)

		it('lets a route flagged meta.public through', () => {
			const { guard, next } = harness(true)

			guard(
				route('SomeFuturePublicPage', '/pub', { public: true }),
				route('Dashboard', '/'),
				next,
			)

			expect(next).toHaveBeenCalledWith()
		})

		it('does not consult the session store before the route is known to be protected', () => {
			const getSessionStore = vi.fn(() => ({ isLocked: true }))
			const guard = createVaultGuard(getSessionStore)

			guard(route(LOCK_ROUTE_NAME, '/lock'), route('Dashboard', '/'), vi.fn())

			expect(getSessionStore).not.toHaveBeenCalled()
		})
	})

	describe('when the vault is unlocked', () => {
		it.each(PROTECTED_ROUTES)('allows %s', (name) => {
			const { guard, next } = harness(false)

			guard(route(name), route('Dashboard', '/'), next)

			expect(next).toHaveBeenCalledTimes(1)
			expect(next).toHaveBeenCalledWith()
		})
	})

	it('is synchronous — it must not defer the decision behind a promise', () => {
		const { guard, next } = harness(true)

		const returned = guard(
			route('SecretList', '/secrets'),
			route('Dashboard', '/'),
			next,
		)

		expect(returned).toBeUndefined()
		expect(next).toHaveBeenCalledTimes(1)
	})
})

describe('isPublicRoute', () => {
	it('returns false for a null route', () => {
		expect(isPublicRoute(null)).toBe(false)
	})

	it('returns false for a protected route', () => {
		expect(isPublicRoute(route('SecretList'))).toBe(false)
	})

	it('honours the allow-list', () => {
		expect(isPublicRoute(route('LinkShareAccess'))).toBe(true)
	})

	it('honours meta.public', () => {
		expect(isPublicRoute(route('Anything', '/x', { public: true }))).toBe(true)
	})

	it('does not treat a falsy meta.public as public', () => {
		expect(isPublicRoute(route('Anything', '/x', { public: false }))).toBe(false)
	})
})

describe('createVaultGuard — deny by default', () => {
	/*
	 * The tests above all name a route from PROTECTED_ROUTES. That leaves the
	 * property the whole change rests on unasserted: the guard denies anything
	 * it does not recognise. A refactor to an explicit
	 * `if (PROTECTED_ROUTES.includes(to.name))` check would keep every one of
	 * them green while silently ungating every unrecognised and every
	 * newly-added route. These are the canaries for that.
	 */
	it.each([
		['an unnamed route', { name: undefined, fullPath: '/mystery', meta: {} }],
		['a null name', { name: null, fullPath: '/mystery', meta: {} }],
		['an unrecognised name', route('SomePageAddedLater', '/later')],
		['a route with no meta at all', { name: 'NoMeta', fullPath: '/no-meta' }],
		[
			'a truthy-but-not-true meta.public',
			route('Sneaky', '/sneaky', { public: 'yes' }),
		],
	])('denies %s while locked', (_label, to) => {
		const { guard, next } = harness(true)

		guard(to, route('Dashboard', '/'), next)

		expect(next).toHaveBeenCalledTimes(1)
		expect(next).toHaveBeenCalledWith({
			name: LOCK_ROUTE_NAME,
			query: { returnUrl: to.fullPath },
		})
	})

	it.each([
		['isLocked is absent', {}],
		['isLocked is undefined', { isLocked: undefined }],
		['isLocked is the string "false"', { isLocked: 'false' }],
		['the store itself is undefined', undefined],
	])('denies when %s', (_label, store) => {
		const next = vi.fn()
		const guard = createVaultGuard(() => store)

		guard(route('SecretList'), route('Dashboard', '/'), next)

		expect(next).toHaveBeenCalledWith({
			name: LOCK_ROUTE_NAME,
			query: { returnUrl: '/secretlist' },
		})
	})

	it('allows only an explicit isLocked === false', () => {
		const next = vi.fn()
		createVaultGuard(() => ({ isLocked: false }))(
			route('SecretList'),
			route('Dashboard', '/'),
			next,
		)

		expect(next).toHaveBeenCalledWith()
	})

	it('lets a throwing store factory abort the navigation rather than allowing', () => {
		// Fail-closed by propagation: vue-router aborts on a thrown guard. The
		// guard deliberately has no try/catch, and wrapping it would convert
		// this into a silent allow.
		const next = vi.fn()
		const guard = createVaultGuard(() => {
			throw new Error('pinia not ready')
		})

		expect(() =>
			guard(route('SecretList'), route('Dashboard', '/'), next),
		).toThrow('pinia not ready')
		expect(next).not.toHaveBeenCalled()
	})

	it('stays in step with src/manifest.json', () => {
		// Makes the comment above PROTECTED_ROUTES load-bearing: a page added to
		// the manifest without being classified here fails this test instead of
		// silently inheriting whichever default the guard happens to apply.
		const classified = [
			...PROTECTED_ROUTES,
			...PUBLIC_ROUTE_NAMES,
			LOCK_ROUTE_NAME,
		].sort()

		expect(manifest.pages.map((page) => page.id).sort()).toEqual(classified)
	})
})

describe('handleLockTransition', () => {
	/*
	 * The mid-session half of the invariant: beforeEach only fires on
	 * navigation, so a session timeout or "Lock vault" while the user sits on a
	 * resolved route is this function's responsibility. Previously defended by
	 * a comment only — which is the same shape as the bug this change fixes.
	 */
	it('redirects to the lock screen with the current path as returnUrl', () => {
		const router = { replace: vi.fn() }

		expect(
			handleLockTransition(true, route('SecretList', '/secrets'), router),
		).toBe(true)
		expect(router.replace).toHaveBeenCalledWith({
			name: LOCK_ROUTE_NAME,
			query: { returnUrl: '/secrets' },
		})
	})

	it('does nothing when the vault unlocks', () => {
		const router = { replace: vi.fn() }

		expect(
			handleLockTransition(false, route('SecretList', '/secrets'), router),
		).toBe(false)
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('does not redirect when already on the lock screen', () => {
		const router = { replace: vi.fn() }

		expect(handleLockTransition(true, route('Lock', '/lock'), router)).toBe(
			false,
		)
		expect(router.replace).not.toHaveBeenCalled()
	})

	it.each(PUBLIC_ROUTE_NAMES)('leaves the public route %s alone', (name) => {
		const router = { replace: vi.fn() }

		expect(handleLockTransition(true, route(name), router)).toBe(false)
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('redirects on a null route rather than assuming it is safe', () => {
		const router = { replace: vi.fn() }

		expect(handleLockTransition(true, null, router)).toBe(true)
		expect(router.replace).toHaveBeenCalledWith({
			name: LOCK_ROUTE_NAME,
			query: { returnUrl: undefined },
		})
	})

	// The mirror of createVaultGuard's `store?.isLocked !== false`: only an
	// explicit `false` means unlocked. A truthy-but-not-`true` or absent value
	// must still evict, because nothing else will — beforeEach does not fire
	// without a navigation, which is the whole reason this function exists.
	it.each([
		['undefined', undefined],
		['null', null],
		['a truthy non-boolean', 'yes'],
		['0', 0],
	])('evicts on a non-boolean locked value (%s)', (_label, locked) => {
		const router = { replace: vi.fn() }

		expect(
			handleLockTransition(locked, route('SecretList', '/secrets'), router),
		).toBe(true)
		expect(router.replace).toHaveBeenCalledWith({
			name: LOCK_ROUTE_NAME,
			query: { returnUrl: '/secrets' },
		})
	})
})

describe('isPublicSurface', () => {
	it('treats the anonymous shell as public whatever the hash is', () => {
		expect(
			isPublicSurface({
				pathname: '/index.php/apps/keepiq/public',
				hash: '',
			}),
		).toBe(true)
		expect(
			isPublicSurface({
				pathname: '/index.php/apps/keepiq/public',
				hash: '#/share/request/tok',
			}),
		).toBe(true)
	})

	it('recognises the recipient PATH routes on either shell', () => {
		// The link builders emit paths (createWebHistory), on the public shell
		// for recipients and resolvable on the authenticated shell too.
		for (const pathname of [
			'/index.php/apps/keepiq/public/share/request/tok',
			'/apps/keepiq/public/share/link/tok',
			'/index.php/apps/keepiq/share/request/tok',
			'/index.php/apps/keepiq/share/link/tok',
			'/apps/keepiq/send/tok',
		]) {
			expect(isPublicSurface({ pathname, hash: '' }), pathname).toBe(true)
		}
	})

	it('still recognises the RETIRED hash forms, links to which are in the wild', () => {
		// applyHashRouteHandoff() rewrites these at bootstrap, but the
		// classification must not depend on rewrite order.
		for (const hash of [
			'#/share/request/tok',
			'#/share/link/tok',
			'#/send/tok',
		]) {
			expect(
				isPublicSurface({ pathname: '/index.php/apps/keepiq/', hash }),
			).toBe(true)
		}
	})

	it('leaves ordinary vault URLs alone', () => {
		expect(
			isPublicSurface({
				pathname: '/index.php/apps/keepiq/',
				hash: '#/secrets',
			}),
		).toBe(false)
		expect(
			isPublicSurface({
				pathname: '/index.php/apps/keepiq/secrets',
				hash: '',
			}),
		).toBe(false)
		// A nested segment that merely CONTAINS a recipient word is not one.
		expect(
			isPublicSurface({
				pathname: '/apps/keepiq/secrets/send/decoy',
				hash: '',
			}),
		).toBe(false)
		// A send URL's #k= fragment alone does not make a surface public.
		expect(
			isPublicSurface({ pathname: '/apps/keepiq/secrets', hash: '#k=abc' }),
		).toBe(false)
		expect(isPublicSurface({})).toBe(false)
		expect(isPublicSurface()).toBe(false)
	})

	it('matches the shell on a segment boundary, not as a substring', () => {
		// A substring test on '/apps/keepiq/public' answered true for every one
		// of these. It is not the security gate, but a classifier that is wrong
		// for a whole family of paths invites being mistaken for one.
		for (const pathname of [
			'/apps/keepiq/publications/share/link/tok',
			'/index.php/apps/keepiq/publications',
			'/apps/keepiq/publicfoo',
			'/apps/keepiq/secrets/public',
		]) {
			expect(isPublicSurface({ pathname, hash: '' }), pathname).toBe(false)
		}
	})

	it('is not fooled by another app whose id starts with this one', () => {
		for (const pathname of [
			'/apps/keepiq-old/public/share/link/tok',
			'/index.php/apps/keepiqfoo/send/tok',
		]) {
			expect(isPublicSurface({ pathname, hash: '' }), pathname).toBe(false)
		}
	})

	it('still works when Nextcloud is served from a sub-directory', () => {
		// The app segment is located, not anchored, so a webroot install keeps
		// resolving — the boundary check must not cost that.
		for (const pathname of [
			'/nextcloud/index.php/apps/keepiq/public',
			'/nextcloud/apps/keepiq/public/share/link/tok',
			'/nextcloud/index.php/apps/keepiq/send/tok',
		]) {
			expect(isPublicSurface({ pathname, hash: '' }), pathname).toBe(true)
		}
	})

	it('answers without a resolved route, which is the whole point', () => {
		// CnAppRoot reads `supportDialog` once in setup(), before the router has
		// resolved a name — so a route-based check is still undefined there.
		expect(
			isPublicSurface({
				pathname: '/index.php/apps/keepiq/public',
				hash: '#/share/request/tok',
			}),
		).toBe(true)
	})
})

describe('manifestForLockState', () => {
	const MANIFEST = {
		version: 1,
		pages: [],
		walkthrough: { enabled: true, tours: [] },
	}

	it('withholds the walkthrough while the vault is locked', () => {
		// CnAppRoot fetches the tour's completion preference as soon as it sees
		// `manifest.walkthrough`, and it mounts OUTSIDE the router guard — so
		// this is the only thing keeping that request off the wire behind the
		// lock screen.
		const shown = manifestForLockState(MANIFEST, { isLocked: true })

		expect(shown.walkthrough).toBeUndefined()
		expect(shown.pages).toBe(MANIFEST.pages)
	})

	it('offers the walkthrough once the vault is unlocked', () => {
		// Withheld, not disabled: a first-visit tour must still run on a user's
		// first UNLOCKED visit.
		expect(manifestForLockState(MANIFEST, { isLocked: false })).toBe(MANIFEST)
	})

	it('fails closed when the store is missing or its flag is not a boolean', () => {
		// Same posture as createVaultGuard: only an explicit `false` unlocks.
		// A store that failed to initialise must withhold the tour, not ship it.
		for (const store of [
			undefined,
			null,
			{},
			{ isLocked: undefined },
			{ isLocked: 'no' },
			{ isLocked: 0 },
		]) {
			expect(manifestForLockState(MANIFEST, store).walkthrough).toBeUndefined()
		}
	})

	it('leaves a manifest without a walkthrough untouched', () => {
		const plain = { version: 1, pages: [] }

		expect(manifestForLockState(plain, { isLocked: true })).toBe(plain)
	})

	it('does not mutate the manifest it was given', () => {
		manifestForLockState(MANIFEST, { isLocked: true })

		expect(MANIFEST.walkthrough).toBeDefined()
	})
})
