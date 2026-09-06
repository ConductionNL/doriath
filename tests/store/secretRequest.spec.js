/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `useSecretRequestStore` (`src/store/modules/secretRequest.js`).
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#13.1
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretRequestStore } from '../../src/store/modules/secretRequest.js'

vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async () => 'PUBKEY_HANDLE'),
	rsaEncrypt: vi.fn(async (value) => `ENC(${value})`),
}))

describe('useSecretRequestStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchRequests', () => {
		it('hydrates the user-scoped list', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: [
					{ id: 'r1', status: 'pending', token: 'tok-1' },
					{ id: 'r2', status: 'fulfilled', token: 'tok-2' },
				],
			})
			const store = useSecretRequestStore()
			await store.fetchRequests()
			expect(store.secretRequests).toHaveLength(2)
			expect(store.pendingCount).toBe(1)
		})
	})

	describe('createRequest', () => {
		it('POSTs the payload and prepends the row', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({
				data: { id: 'r-new', token: 'tok-new', status: 'pending' },
			})
			const store = useSecretRequestStore()
			const row = await store.createRequest({
				secretId: 'sec-1',
				encryptionSuiteId: 'suite-1',
				requestedFields: ['key'],
			})
			expect(row.id).toBe('r-new')
			expect(store.secretRequests[0].id).toBe('r-new')
		})
	})

	describe('createReRequest', () => {
		it('passes isReRequest=true to createRequest', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({
				data: {
					id: 'r-rr',
					token: 't',
					status: 'pending',
					isReRequest: true,
				},
			})
			const store = useSecretRequestStore()
			await store.createReRequest(
				'sec-1',
				'suite-1',
				['key'],
				'2026-12-31T00:00:00Z',
			)
			const body = post.mock.calls[0][1]
			expect(body.isReRequest).toBe(true)
			expect(body.expiresAt).toBe('2026-12-31T00:00:00Z')
		})
	})

	describe('revokeRequest', () => {
		it('DELETEs and removes the row from local state', async () => {
			vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useSecretRequestStore()
			store.secretRequests = [{ id: 'r1' }, { id: 'r2' }]
			await store.revokeRequest('r1')
			expect(store.secretRequests).toEqual([{ id: 'r2' }])
		})
	})

	describe('fetchPublicRequest', () => {
		it('hydrates publicRequest with the certificate + fields', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: {
					token: 'tok-1',
					status: 'pending',
					requested_fields: ['key', 'login'],
					public_certificate: 'PEM',
				},
			})
			const store = useSecretRequestStore()
			await store.fetchPublicRequest('tok-1')
			expect(store.publicRequest.token).toBe('tok-1')
			expect(store.publicRequest.requested_fields).toEqual(['key', 'login'])
		})
	})

	describe('submitFill', () => {
		it('encrypts every field with the public certificate before POSTing', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: {
					token: 'tok-1',
					status: 'pending',
					requested_fields: ['key', 'login'],
					public_certificate: 'PEM',
				},
			})
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: { status: 'fulfilled' } })
			const store = useSecretRequestStore()
			const result = await store.submitFill('tok-1', {
				key: 'p4ss',
				login: 'alice',
			})
			expect(result.status).toBe('fulfilled')
			const body = post.mock.calls[0][1]
			expect(body.encryptedFields).toEqual({
				key: 'ENC(p4ss)',
				login: 'ENC(alice)',
			})
		})

		it('throws when the certificate is missing', async () => {
			const store = useSecretRequestStore()
			store.publicRequest = { token: 'tok-x', public_certificate: '' }
			await expect(store.submitFill('tok-x', { key: 'x' })).rejects.toThrow(
				/certificate/,
			)
		})

		it('throws when a field is empty (server would have rejected it anyway)', async () => {
			const store = useSecretRequestStore()
			store.publicRequest = { token: 'tok-x', public_certificate: 'PEM' }
			await expect(store.submitFill('tok-x', { key: '' })).rejects.toThrow(
				/required/,
			)
		})
	})
})
