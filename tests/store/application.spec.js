/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the `useApplicationStore` Pinia store
 * (`src/store/modules/application.js`).
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#15.1
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useApplicationStore } from '../../src/store/modules/application.js'

describe('useApplicationStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchApplications', () => {
		it('hydrates the list and totalCount', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: [
					{ id: 'app-1', name: 'A', status: 'active', type: 'internal' },
					{ id: 'app-2', name: 'B', status: 'pending', type: 'external' },
				],
			})
			const store = useApplicationStore()
			await store.fetchApplications()

			expect(store.applications).toHaveLength(2)
			expect(store.totalCount).toBe(2)
			expect(store.loading).toBe(false)
		})

		it('clears loading even when the request throws', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('boom'))
			const store = useApplicationStore()
			await expect(store.fetchApplications()).rejects.toThrow('boom')
			expect(store.loading).toBe(false)
		})
	})

	describe('fetchPending', () => {
		it('hydrates the pending queue', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: [{ id: 'pen-1', name: 'CI Bot', status: 'pending' }],
			})
			const store = useApplicationStore()
			await store.fetchPending()

			expect(store.pendingApplications).toHaveLength(1)
			expect(store.pendingCount).toBe(1)
		})
	})

	describe('registerApplication', () => {
		it('posts the form and pushes the row onto the list', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({
				data: {
					id: 'app-new',
					name: 'New',
					status: 'pending',
					type: 'external',
				},
			})
			const store = useApplicationStore()

			const result = await store.registerApplication({
				name: 'New',
				type: 'external',
			})

			expect(post).toHaveBeenCalledOnce()
			const [url, body] = post.mock.calls[0]
			expect(url).toBe('/apps/keepiq/api/v1/applications')
			expect(body).toEqual({
				name: 'New',
				description: null,
				type: 'external',
				csr: null,
			})
			expect(result.id).toBe('app-new')
			expect(store.applications).toHaveLength(1)
			expect(store.pendingApplications).toHaveLength(1)
		})

		it('surfaces the one-time private_key into store state', async () => {
			const pem =
				'-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----'
			vi.spyOn(axios, 'post').mockResolvedValue({
				data: { id: 'app-x', name: 'X', status: 'active', private_key: pem },
			})
			const store = useApplicationStore()
			await store.registerApplication({ name: 'X', type: 'internal' })

			expect(store.oneTimePrivateKey).toBe(pem)
			expect(store.oneTimePrivateKeyAppId).toBe('app-x')
		})
	})

	describe('approveApplication', () => {
		it('moves the row from pending into the main list', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({
				data: { id: 'app-1', name: 'A', status: 'active' },
			})
			const store = useApplicationStore()
			store.pendingApplications = [
				{ id: 'app-1', name: 'A', status: 'pending' },
			]
			store.applications = [{ id: 'app-1', name: 'A', status: 'pending' }]

			await store.approveApplication('app-1')

			expect(store.pendingApplications).toHaveLength(0)
			expect(store.applications[0].status).toBe('active')
		})

		it('surfaces the private_key returned on no-CSR approval', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({
				data: { id: 'app-1', private_key: 'PEM-2' },
			})
			const store = useApplicationStore()
			store.pendingApplications = [{ id: 'app-1' }]

			await store.approveApplication('app-1')

			expect(store.oneTimePrivateKey).toBe('PEM-2')
			expect(store.oneTimePrivateKeyAppId).toBe('app-1')
		})
	})

	describe('rejectApplication', () => {
		it('removes the row from both lists', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
			const store = useApplicationStore()
			store.pendingApplications = [{ id: 'app-1' }, { id: 'app-2' }]
			store.applications = [{ id: 'app-1' }, { id: 'app-2' }]

			await store.rejectApplication('app-1')

			expect(store.pendingApplications.map((a) => a.id)).toEqual(['app-2'])
			expect(store.applications.map((a) => a.id)).toEqual(['app-2'])
		})
	})

	describe('deleteApplication', () => {
		it('calls DELETE and prunes the row', async () => {
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useApplicationStore()
			store.applications = [{ id: 'app-1' }, { id: 'app-2' }]
			store.pendingApplications = [{ id: 'app-1' }]
			store.currentApplication = { id: 'app-1' }

			await store.deleteApplication('app-1')

			expect(del).toHaveBeenCalledWith(
				'/apps/keepiq/api/v1/applications/app-1',
			)
			expect(store.applications.map((a) => a.id)).toEqual(['app-2'])
			expect(store.pendingApplications).toHaveLength(0)
			expect(store.currentApplication).toBeNull()
		})
	})

	describe('clearOneTimePrivateKey', () => {
		it('resets transient fields to null', () => {
			const store = useApplicationStore()
			store.oneTimePrivateKey = 'leaked'
			store.oneTimePrivateKeyAppId = 'app-1'

			store.clearOneTimePrivateKey()

			expect(store.oneTimePrivateKey).toBeNull()
			expect(store.oneTimePrivateKeyAppId).toBeNull()
		})
	})

	describe('fetchCertificate', () => {
		it('returns the certificate from the API', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: {
					id: 'app-1',
					certificate:
						'-----BEGIN CERTIFICATE-----STUB-----END CERTIFICATE-----',
				},
			})
			const store = useApplicationStore()
			const cert = await store.fetchCertificate('app-1')
			expect(cert).toBe(
				'-----BEGIN CERTIFICATE-----STUB-----END CERTIFICATE-----',
			)
		})

		it('returns empty when the API omits the certificate field', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
			const store = useApplicationStore()
			const cert = await store.fetchCertificate('app-1')
			expect(cert).toBe('')
		})
	})

	describe('listApplicationSecrets', () => {
		it('returns the secrets array from the paginated envelope', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: { items: [{ id: 'sec-1', name: 'X' }], total: 1 },
			})
			const store = useApplicationStore()
			const secrets = await store.listApplicationSecrets('app-1')
			expect(secrets).toHaveLength(1)
			expect(secrets[0].id).toBe('sec-1')
		})

		it('returns the array directly when the API serves a bare list', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: [{ id: 'sec-1' }, { id: 'sec-2' }],
			})
			const store = useApplicationStore()
			const secrets = await store.listApplicationSecrets('app-1')
			expect(secrets).toHaveLength(2)
		})
	})

	describe('writeSecretForApplication', () => {
		it('throws when the application has no active suite', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: { id: 'app-1', certificate: '' },
			})

			const store = useApplicationStore()
			await expect(
				store.writeSecretForApplication('app-1', { name: 'X', key: 'k' }),
			).rejects.toThrow('Application has no active EncryptionSuite')
		})
	})
})
