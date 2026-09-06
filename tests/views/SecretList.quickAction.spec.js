/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dashboard's "New secret" tile deep-links to `/secrets?action=create`.
 * The tile navigates through the router (a full page load would drop the
 * in-memory vault key and land on the lock screen), so the list view must
 * consume the marker itself: open the create-secret dialog, then strip
 * `action` from the URL so a refresh — which re-locks the vault and
 * round-trips the query through the lock screen's `returnUrl` — does not
 * re-open the dialog on every unlock.
 *
 * Options-object style (like SecretList.registryDispatch.spec.js): mounting
 * the whole list view drags in the folder tree, search and type catalogue,
 * none of which this behaviour touches.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 */

import { describe, expect, it, vi } from 'vitest'
import SecretList from '../../src/views/SecretList.vue'

const actionWatcher = SecretList.watch['$route.query.action']

describe('SecretList — dashboard quick action (?action=create)', () => {
	it('watches the action query immediately, so a fresh mount sees the marker', () => {
		// CnPageRenderer keeps the list mounted when only the query changes,
		// so this must be a watcher; `immediate` covers the fresh-mount case
		// (dashboard tile → route change → new page component).
		expect(actionWatcher.immediate).toBe(true)
		expect(typeof actionWatcher.handler).toBe('function')
	})

	it('fires only on the create marker', () => {
		const consumeCreateAction = vi.fn()

		actionWatcher.handler.call({ consumeCreateAction }, 'create')
		expect(consumeCreateAction).toHaveBeenCalledTimes(1)

		actionWatcher.handler.call({ consumeCreateAction }, undefined)
		actionWatcher.handler.call({ consumeCreateAction }, 'register')
		expect(consumeCreateAction).toHaveBeenCalledTimes(1)
	})

	it('strips the marker FIRST, then opens the create dialog', async () => {
		// Order is the contract: CnAppRoot closes the active registry modal on
		// every route change, and the query replace is one — a dialog opened
		// before the replace is closed in the same tick it opened.
		const calls = []
		const ctx = {
			openCreateSecret: vi.fn(() => calls.push('open')),
			$route: { query: { action: 'create', view: 'cards' } },
			$router: {
				replace: vi.fn((to) => {
					calls.push('replace')
					return Promise.resolve(to)
				}),
			},
			$nextTick: () => Promise.resolve(),
		}

		await SecretList.methods.consumeCreateAction.call(ctx)

		expect(ctx.$router.replace).toHaveBeenCalledWith({
			query: { view: 'cards' },
		})
		expect(ctx.openCreateSecret).toHaveBeenCalledTimes(1)
		expect(calls).toEqual(['replace', 'open'])
	})
})
