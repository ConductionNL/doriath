/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `useShareStore` (`src/store/modules/share.js`).
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#16.1
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useShareStore } from '../../src/store/modules/share.js'

vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async () => 'PUBKEY_HANDLE'),
	rsaEncrypt: vi.fn(async (value) => `ENC(${value})`),
}))

describe('useShareStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchShares', () => {
		it('hydrates the shares list', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: [
					{ id: 's1', target_user_id: 'alice' },
					{ id: 's2', target_user_id: 'bob', group_share_id: 'g1' },
				],
			})
			const store = useShareStore()
			await store.fetchShares('sec-1')

			expect(store.shares).toHaveLength(2)
			expect(store.recipientCount).toBe(2)
			expect(store.loading).toBe(false)
		})

		it('captures the error message and rethrows', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue({
				response: { data: { message: 'nope' } },
			})
			const store = useShareStore()
			await expect(store.fetchShares('sec-1')).rejects.toBeTruthy()
			expect(store.error).toBe('nope')
		})
	})

	describe('encryptForRecipient', () => {
		it('produces a base64 envelope for each non-empty field', async () => {
			const store = useShareStore()
			const out = await store.encryptForRecipient(
				{ key: 'p4ss', login: 'alice', empty: '' },
				'PEM',
			)
			expect(out).toEqual({ key: 'ENC(p4ss)', login: 'ENC(alice)' })
		})

		it('errors when the recipient certificate is missing', async () => {
			const store = useShareStore()
			await expect(
				store.encryptForRecipient({ key: 'x' }, ''),
			).rejects.toThrow(/encryption suite/)
		})
	})

	describe('createShare', () => {
		it('POSTs the share target and appends it to the list', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({
				data: {
					id: 's-new',
					target_user_id: 'carol',
					recipientSecretId: 'r1',
				},
			})
			const store = useShareStore()
			const row = await store.createShare('sec-1', 'carol', 'r1', null)
			expect(post).toHaveBeenCalled()
			expect(row.id).toBe('s-new')
			expect(store.shares[0].id).toBe('s-new')
		})
	})

	describe('revokeShare', () => {
		it('DELETEs and removes the share from the list', async () => {
			vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useShareStore()
			store.shares = [{ id: 's1' }, { id: 's2' }]
			await store.revokeShare('s1')
			expect(store.shares).toEqual([{ id: 's2' }])
		})
	})

	describe('createBatchShares', () => {
		it('POSTs once per recipient and returns the response rows', async () => {
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValueOnce({
					data: { id: 'r1', target_user_id: 'alice' },
				})
				.mockResolvedValueOnce({ data: { id: 'r2', target_user_id: 'bob' } })
			const store = useShareStore()
			const rows = await store.createBatchShares(
				'sec-1',
				[
					{ targetUserId: 'alice', recipientSecretId: 'a1' },
					{ targetUserId: 'bob', recipientSecretId: 'b1' },
				],
				'g1',
			)
			expect(post).toHaveBeenCalledTimes(2)
			expect(rows).toHaveLength(2)
			expect(rows[1].id).toBe('r2')
		})
	})

	describe('reset', () => {
		it('clears shares + error', () => {
			const store = useShareStore()
			store.shares = [{ id: 's1' }]
			store.error = 'x'
			store.reset()
			expect(store.shares).toEqual([])
			expect(store.error).toBeNull()
		})
	})

	describe('syncUpdate (§11.5)', () => {
		it('returns updated:0 when the secret has no recipients', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
			const put = vi.spyOn(axios, 'put')
			const store = useShareStore()

			const result = await store.syncUpdate(
				'sec-1',
				{ key: 'newPW' },
				'2026-06-12T00:00:00Z',
			)

			expect(result.updated).toBe(0)
			expect(put).not.toHaveBeenCalled()
		})

		it('encrypts the plaintext for each recipient and PUTs the batch', async () => {
			const store = useShareStore()
			// Pre-seed the share list so fetchShares is short-circuited
			// (the recipient certificates are attached to each row).
			store.shares = [
				{ id: 'sh1', secretId: 'copy-bob', recipientCertificate: 'PEM-bob' },
				{
					id: 'sh2',
					secretId: 'copy-carol',
					recipientCertificate: 'PEM-carol',
				},
			]

			const put = vi
				.spyOn(axios, 'put')
				.mockResolvedValue({ data: { updated: 2 } })
			const result = await store.syncUpdate(
				'sec-1',
				{ key: 'newPW', login: 'bob@example.com' },
				'2026-06-12T00:00:00Z',
			)

			expect(put).toHaveBeenCalledTimes(1)
			const [url, body] = put.mock.calls[0]
			expect(url).toContain('/api/v1/secrets/sec-1/sync')
			expect(body.expectedUpdatedAt).toBe('2026-06-12T00:00:00Z')
			expect(body.updates).toHaveLength(2)
			expect(body.updates[0].encryptedKey).toBe('ENC(newPW)')
			expect(body.updates[0].encryptedLogin).toBe('ENC(bob@example.com)')
			expect(body.updates[0].secretId).toBe('copy-bob')
			expect(result.updated).toBe(2)
		})

		it('skips recipients whose certificate has been cleared', async () => {
			const store = useShareStore()
			store.shares = [
				{ id: 'sh1', secretId: 'copy-bob', recipientCertificate: 'PEM-bob' },
				{ id: 'sh2', secretId: 'copy-stale', recipientCertificate: '' },
			]

			const put = vi
				.spyOn(axios, 'put')
				.mockResolvedValue({ data: { updated: 1 } })
			await store.syncUpdate('sec-1', { key: 'newPW' }, null)

			const body = put.mock.calls[0][1]
			expect(body.updates).toHaveLength(1)
			expect(body.updates[0].secretId).toBe('copy-bob')
		})

		it('surfaces 409 conflicts via the error field and rethrows', async () => {
			const store = useShareStore()
			store.shares = [
				{ id: 'sh1', secretId: 'copy-bob', recipientCertificate: 'PEM-bob' },
			]
			vi.spyOn(axios, 'put').mockRejectedValue({
				response: { status: 409, data: { message: 'stale lock' } },
			})

			await expect(
				store.syncUpdate('sec-1', { key: 'newPW' }, '2026-06-12T00:00:00Z'),
			).rejects.toBeTruthy()
			expect(store.error).toBe('stale lock')
		})
	})
})
