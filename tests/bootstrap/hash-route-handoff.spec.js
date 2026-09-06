/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for the legacy hash-route → path-route handoff.
 *
 * The regression these lock down: recipient links built under the retired
 * hash scheme (`/public#/share/link/<token>`) were mailed to people with no
 * Nextcloud account and no way to regenerate them. The createWebHistory
 * router never reads the fragment, so without the handoff those links land
 * an account-less recipient on the lock screen asking for a master password
 * they do not have.
 *
 * The security-sensitive case: a legacy fragment-mode send link carries the
 * content key INSIDE the fragment (`#/send/<t>?k=<key>`). The rewrite must
 * keep the key in the fragment (`#k=`), because a real `?k=` query would be
 * transmitted to (and logged by) the server on the next load.
 */

import { describe, expect, it, vi } from 'vitest'
import {
	applyHashRouteHandoff,
	hashRouteHandoffTarget,
	matchRouterBase,
} from '../../src/bootstrap/hash-route-handoff.js'

describe('matchRouterBase', () => {
	it('matches both URL forms of the authenticated shell', () => {
		expect(matchRouterBase('/apps/keepiq/secrets')).toBe('/apps/keepiq')
		expect(matchRouterBase('/index.php/apps/keepiq/secrets')).toBe(
			'/index.php/apps/keepiq',
		)
		expect(matchRouterBase('/apps/keepiq')).toBe('/apps/keepiq')
		expect(matchRouterBase('/apps/keepiq/')).toBe('/apps/keepiq')
	})

	it('extends the base by /public on the anonymous shell', () => {
		expect(matchRouterBase('/apps/keepiq/public')).toBe('/apps/keepiq/public')
		expect(matchRouterBase('/index.php/apps/keepiq/public/share/link/t')).toBe(
			'/index.php/apps/keepiq/public',
		)
	})

	it('does not eat a path segment that merely starts with "public"', () => {
		// `(?:\/|$)` refuses the partial word, so the optional /public group
		// backtracks and the base stays the app root.
		expect(matchRouterBase('/apps/keepiq/publications')).toBe('/apps/keepiq')
	})

	it('returns null off the app entirely', () => {
		expect(matchRouterBase('/apps/other/thing')).toBe(null)
		expect(matchRouterBase('')).toBe(null)
		expect(matchRouterBase(undefined)).toBe(null)
	})
})

describe('hashRouteHandoffTarget', () => {
	it('rewrites a legacy recipient link on the public shell to the path form', () => {
		expect(
			hashRouteHandoffTarget({
				pathname: '/index.php/apps/keepiq/public',
				hash: '#/share/link/tok-1',
				search: '',
			}),
		).toBe('/index.php/apps/keepiq/public/share/link/tok-1')
	})

	it('keeps a fragment-mode send key IN the fragment, never in the query', () => {
		const target = hashRouteHandoffTarget({
			pathname: '/apps/keepiq/public',
			hash: '#/send/tok-2?k=a1b2-c3_d4',
			search: '',
		})

		expect(target).toBe('/apps/keepiq/public/send/tok-2#k=a1b2-c3_d4')
		// The load-bearing property, stated directly: nothing before the '#'
		// carries the key, so the server never sees it.
		expect(target.split('#')[0]).not.toContain('k=')
	})

	it('promotes ordinary hash-route query params to a real query', () => {
		expect(
			hashRouteHandoffTarget({
				pathname: '/apps/keepiq/',
				hash: '#/secrets?action=create',
				search: '',
			}),
		).toBe('/apps/keepiq/secrets?action=create')
	})

	it('merges an existing real query with the hash route query', () => {
		expect(
			hashRouteHandoffTarget({
				pathname: '/apps/keepiq/',
				hash: '#/secrets?action=create',
				search: '?foo=1',
			}),
		).toBe('/apps/keepiq/secrets?foo=1&action=create')
	})

	it('rewrites a legacy authenticated deep link', () => {
		// Old unified-search / notification links: full page load, so the
		// vault guard then bounces to /lock with this path as returnUrl.
		expect(
			hashRouteHandoffTarget({
				pathname: '/index.php/apps/keepiq/',
				hash: '#/secrets/42',
				search: '',
			}),
		).toBe('/index.php/apps/keepiq/secrets/42')
	})

	it.each([
		['no hash', { pathname: '/apps/keepiq/public', hash: '', search: '' }],
		[
			'a NEW send link fragment (#k= is not a route)',
			{ pathname: '/apps/keepiq/public/send/t', hash: '#k=abc', search: '' },
		],
		[
			'a plain anchor',
			{ pathname: '/apps/keepiq/secrets', hash: '#section', search: '' },
		],
		[
			'a non-keepiq URL',
			{ pathname: '/apps/files/', hash: '#/share/link/t', search: '' },
		],
	])('leaves %s alone', (_label, location) => {
		expect(hashRouteHandoffTarget(location)).toBe(null)
	})
})

describe('applyHashRouteHandoff', () => {
	it('replaceStates the rewritten URL and preserves history state', () => {
		const state = { marker: 1 }
		const win = {
			location: {
				pathname: '/apps/keepiq/public',
				hash: '#/send/tok?k=KEY',
				search: '',
			},
			history: { state, replaceState: vi.fn() },
		}

		expect(applyHashRouteHandoff(win)).toBe(true)
		expect(win.history.replaceState).toHaveBeenCalledWith(
			state,
			'',
			'/apps/keepiq/public/send/tok#k=KEY',
		)
	})

	it('touches nothing when there is no legacy hash route', () => {
		const win = {
			location: { pathname: '/apps/keepiq/secrets', hash: '', search: '' },
			history: { state: null, replaceState: vi.fn() },
		}

		expect(applyHashRouteHandoff(win)).toBe(false)
		expect(win.history.replaceState).not.toHaveBeenCalled()
	})
})
