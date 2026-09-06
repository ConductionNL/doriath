/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the list-request ordering of the `useAttachmentStore`
 * Pinia store (`src/store/modules/attachment.js`).
 *
 * What these lock down:
 *  - A list response whose request has been superseded does NOT write to
 *    the store. The secret-detail sidebar swaps its `:id` without
 *    remounting, so AttachmentPanel refetches in place; without this
 *    guard a slow response for the previous secret lands after the
 *    current one and shows another secret's attachment metadata.
 *  - `reset()` retires whatever is in flight and clears `loading`, so a
 *    reset during a fetch cannot strand the spinner with nothing left
 *    to finish it.
 *
 * The metadata decrypt is deliberately left to fail here: it happens in
 * a swallowing try/catch (undecryptable metadata is shown as such), so a
 * row still arrives and these tests can be about ORDER rather than
 * crypto, which `attachment-crypto.spec.js` already covers.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAttachmentStore } from '../../src/store/modules/attachment.js'

/**
 * A row as the list endpoint returns it, tagged so a test can tell which
 * secret it came from.
 *
 * @param {string} id The attachment id.
 * @return {object} One raw listing row.
 */
function row(id) {
	return {
		id,
		sizeBytes: 1,
		createdAt: '2026-09-01T00:00:00+00:00',
		wrappedFileKey: 'not-a-real-key',
		encryptedMetadata: 'not-real-ciphertext',
	}
}

/**
 * A deferred promise, so a test can decide when a response lands.
 *
 * @return {{promise: Promise<*>, resolve: Function}} The pair.
 */
function deferred() {
	let resolve
	const promise = new Promise((r) => {
		resolve = r
	})
	return { promise, resolve }
}

describe('useAttachmentStore list-request ordering', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('drops a superseded response instead of overwriting the current secret', async () => {
		const slow = deferred()
		vi.spyOn(axios, 'get')
			.mockImplementationOnce(() => slow.promise)
			.mockResolvedValueOnce({ data: [row('from-secret-b')] })

		const store = useAttachmentStore()

		// Secret A's request starts and does not land yet; the sidebar
		// then swaps to secret B, whose request completes first.
		const first = store.fetchAttachments('secret-a')
		await store.fetchAttachments('secret-b')
		expect(store.attachments.map((a) => a.id)).toEqual(['from-secret-b'])

		// A's response arrives late. It must not be written.
		slow.resolve({ data: [row('from-secret-a')] })
		await first

		expect(store.attachments.map((a) => a.id)).toEqual(['from-secret-b'])
	})

	it('keeps the newer error when a superseded request rejects', async () => {
		const slow = deferred()
		vi.spyOn(axios, 'get')
			.mockImplementationOnce(() => slow.promise)
			.mockResolvedValueOnce({ data: [row('from-secret-b')] })

		const store = useAttachmentStore()
		const first = store.fetchAttachments('secret-a')
		await store.fetchAttachments('secret-b')

		slow.resolve(Promise.reject(new Error('secret A timed out')))
		await expect(first).rejects.toThrow('secret A timed out')

		expect(store.error).toBeNull()
		expect(store.loading).toBe(false)
	})

	it('reset() clears the spinner and retires an in-flight request', async () => {
		const slow = deferred()
		vi.spyOn(axios, 'get').mockImplementationOnce(() => slow.promise)

		const store = useAttachmentStore()
		const pending = store.fetchAttachments('secret-a')
		expect(store.loading).toBe(true)

		store.reset()
		expect(store.loading).toBe(false)

		slow.resolve({ data: [row('from-secret-a')] })
		await pending

		expect(store.attachments).toEqual([])
		expect(store.loading).toBe(false)
	})
})
