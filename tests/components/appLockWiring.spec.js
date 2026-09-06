/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Proves App.vue is actually WIRED to handleLockTransition.
 *
 * guards.spec.js tests the helper's decisions in isolation, which leaves the
 * other half untested: that anything calls it. A refactor dropping the watcher
 * — or renaming `isLocked` — passes every one of those tests while the vault
 * inventory stays on screen after an idle auto-lock.
 *
 * That gap is the exact shape of the bug this whole change fixes: a
 * lifecycle-driven redirect that worked in the eventual state, with no test on
 * the thing that triggers it. So the wiring is asserted, not described.
 *
 * The watcher is invoked directly off the component's options object with a
 * stubbed `this`. Mounting the shell would drag in CnAppRoot, the settings
 * dialog and every store, and would test the framework rather than the wiring.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../../src/App.vue'
import { handleLockTransition } from '../../src/router/guards.js'

vi.mock('../../src/router/guards.js', async (importOriginal) => {
	const actual = await importOriginal()

	return { ...actual, handleLockTransition: vi.fn(() => true) }
})

describe('App.vue lock-transition wiring', () => {
	beforeEach(() => {
		handleLockTransition.mockClear()
	})

	it('exposes an isLocked watcher at all', () => {
		// The eviction path is owned by this watcher. If it is gone, the
		// mid-session half of the invariant is gone with it.
		expect(typeof App.watch?.isLocked).toBe('function')
	})

	it('delegates to handleLockTransition with the current route and router', () => {
		const $route = { name: 'SecretList', fullPath: '/secrets', meta: {} }
		const $router = { replace: vi.fn() }
		const context = {
			$route,
			$router,
			offlineStore: {
				online: false,
				servedFromCache: false,
				syncNow: vi.fn(),
			},
		}

		App.watch.isLocked.call(context, true)

		expect(handleLockTransition).toHaveBeenCalledWith(true, $route, $router)
	})

	it('passes the unlock transition through too, rather than filtering it', () => {
		// The helper owns the decision, including "do nothing". A watcher that
		// pre-filtered on `locked` would put that decision back in the shell,
		// where it is not covered by guards.spec.js.
		const $route = { name: 'SecretList', fullPath: '/secrets', meta: {} }
		const $router = { replace: vi.fn() }
		const context = {
			$route,
			$router,
			offlineStore: {
				online: true,
				servedFromCache: false,
				syncNow: vi.fn().mockResolvedValue(undefined),
			},
		}

		App.watch.isLocked.call(context, false)

		expect(handleLockTransition).toHaveBeenCalledWith(false, $route, $router)
	})
})
