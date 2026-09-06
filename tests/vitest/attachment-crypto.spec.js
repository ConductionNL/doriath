/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the attachment store's encrypt-before-upload contract
 * (encrypted-attachments §7.2): no plaintext bytes, filename, or file key
 * in any request body; the listed metadata decrypts back with the
 * session key; download reproduces the exact plaintext bytes.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAttachmentStore } from '../../src/store/modules/attachment.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { sharedKeyPair } from './fixtures/rsa-fixtures.js'

const PLAINTEXT = 'attachment-plaintext-body-0123456789'
const FILENAME = 'aws-recovery-codes.pdf'

describe('attachment store crypto', () => {
	let keys

	beforeEach(async () => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		// Committed RSA-4096 material, not a live keygen: a real 4096-bit
		// keygen is an unbounded random prime search and this hook ran one per
		// test (7.4s of the 33s frontend-unit job in run 31083918823).
		keys = await sharedKeyPair()
		const session = useSessionStore()
		session.certificate = keys.publicKeyPem
		session.cryptoKey = keys.privateKey
	}, 30000)

	it('uploads only ciphertext — no plaintext bytes, filename, or raw key in the request', async () => {
		let body = null
		vi.spyOn(axios, 'post').mockImplementation(async (url, data) => {
			body = data
			return { data: {} }
		})
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })

		const file = new File([new TextEncoder().encode(PLAINTEXT)], FILENAME, {
			type: 'application/pdf',
		})
		const store = useAttachmentStore()
		await store.upload('sec-1', file)

		expect(body).not.toBeNull()
		const wire = JSON.stringify(body)
		expect(wire).not.toContain(PLAINTEXT)
		expect(wire).not.toContain(FILENAME)
		expect(wire).not.toContain(btoa(PLAINTEXT))
		expect(body.blob.length).toBeGreaterThan(0)
		expect(body.encryptedMetadata.length).toBeGreaterThan(0)
		expect(body.wrappedFileKey.length).toBeGreaterThan(0)
	}, 30000)

	it('round-trips: listed metadata decrypts and download reproduces the plaintext', async () => {
		let uploaded = null
		vi.spyOn(axios, 'post').mockImplementation(async (url, data) => {
			uploaded = data
			return { data: {} }
		})
		const getSpy = vi.spyOn(axios, 'get')
		getSpy.mockResolvedValue({ data: [] })

		const store = useAttachmentStore()
		const file = new File([new TextEncoder().encode(PLAINTEXT)], FILENAME, {
			type: 'application/pdf',
		})
		await store.upload('sec-1', file)

		// Serve the uploaded ciphertext back as the server would.
		getSpy.mockImplementation(async (url) => {
			if (url.includes('/blob')) {
				return { data: { blob: uploaded.blob } }
			}
			return {
				data: [
					{
						id: 'att-1',
						sizeBytes: 123,
						createdAt: null,
						encryptedMetadata: uploaded.encryptedMetadata,
						wrappedFileKey: uploaded.wrappedFileKey,
					},
				],
			}
		})

		await store.fetchAttachments('sec-1')
		expect(store.attachments).toHaveLength(1)
		expect(store.attachments[0].filename).toBe(FILENAME)
		expect(store.attachments[0].contentType).toBe('application/pdf')

		// Download decrypts to the exact plaintext (intercept the save; this
		// suite runs in the node environment, so stub the DOM surface).
		const clicks = []
		global.document = {
			createElement: (tag) => {
				const el = { tag, click: () => clicks.push(el) }
				return el
			},
		}
		global.URL.createObjectURL = vi.fn((blob) => {
			global.__lastBlob = blob
			return 'blob:mock'
		})
		global.URL.revokeObjectURL = vi.fn()

		await store.download(store.attachments[0])
		expect(clicks).toHaveLength(1)
		const text = await global.__lastBlob.text()
		expect(text).toBe(PLAINTEXT)
	}, 30000)
})
