/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the `useAuditStore` Pinia store
 * (`src/store/modules/audit.js`).
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-8.1
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuditStore } from '../../src/store/modules/audit.js'

describe('useAuditStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchSecretActivity', () => {
		it('hydrates secretEntries from the owner-scoped endpoint', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: { entries: [{ id: 1, eventType: 'secret.read' }] },
			})
			const store = useAuditStore()
			await store.fetchSecretActivity('sec-1')

			expect(store.secretEntries).toHaveLength(1)
			expect(store.loading).toBe(false)
		})

		it('clears the list on a 404 rather than surfacing an error', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue({ response: { status: 404 } })
			const store = useAuditStore()
			store.secretEntries = [{ id: 9 }]
			await store.fetchSecretActivity('sec-1')

			expect(store.secretEntries).toEqual([])
			expect(store.loading).toBe(false)
		})
	})

	describe('fetchAdminAudit', () => {
		it('stores entries, total, page and limit, and serializes filters', async () => {
			const spy = vi.spyOn(axios, 'get').mockResolvedValue({
				data: { entries: [{ id: 1 }], total: 137, page: 2, limit: 50 },
			})
			const store = useAuditStore()
			store.adminFilters.eventType = 'secret.read'
			store.adminFilters.actor = ''
			await store.fetchAdminAudit(2)

			expect(store.adminEntries).toHaveLength(1)
			expect(store.adminTotal).toBe(137)
			expect(store.adminPage).toBe(2)
			expect(store.adminPageCount).toBe(3)

			// Empty filters omitted; set filters present.
			const params = spy.mock.calls[0][1].params
			expect(params.eventType).toBe('secret.read')
			expect(params).not.toHaveProperty('actor')
			expect(params.page).toBe(2)
		})
	})

	describe('fetchAllAdminForExport', () => {
		it('paginates through every page and accumulates rows', async () => {
			const spy = vi.spyOn(axios, 'get')
			spy.mockResolvedValueOnce({
				data: {
					entries: [{ id: 1 }, { id: 2 }],
					total: 3,
					page: 1,
					limit: 2,
				},
			})
			spy.mockResolvedValueOnce({
				data: { entries: [{ id: 3 }], total: 3, page: 2, limit: 2 },
			})

			const store = useAuditStore()
			store.adminLimit = 2
			const all = await store.fetchAllAdminForExport()

			expect(all).toHaveLength(3)
			expect(spy).toHaveBeenCalledTimes(2)
		})
	})
})
