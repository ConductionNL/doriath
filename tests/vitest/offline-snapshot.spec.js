/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Tests for offline snapshot assembly (offline-readonly-cache §6.2):
 * secret ciphertext stored as-is, plaintext metadata encrypted at rest,
 * and a full round-trip that only opens with the right key.
 */
import { describe, expect, it } from 'vitest'
import { decryptSnapshot, encryptSnapshot } from '../../src/offline/snapshot.js'

/**
 * A fresh non-extractable AES-256-GCM key.
 *
 * @return {Promise<CryptoKey>}
 */
function makeKey() {
	return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, [
		'encrypt',
		'decrypt',
	])
}

const MANIFEST = {
	suite: {
		id: 'suite-1',
		certificate: 'CERT-PEM',
		privateKey: 'ENVELOPE-BLOB',
		status: 'active',
	},
	secrets: [
		{
			id: 'sec-1',
			name: 'Prod API key',
			url: 'https://api.example.gov',
			folderId: 'f-1',
			typeId: 'login',
			key: 'RSA-CIPHERTEXT-KEY',
			login: 'RSA-CIPHERTEXT-LOGIN',
			additionalFields: 'RSA-CIPHERTEXT-EXTRA',
			encryptionSuiteId: 'suite-1',
			expiresAt: null,
		},
	],
	folders: [{ id: 'f-1', parentId: null, name: 'Servers' }],
	syncedAt: '2026-07-20T04:00:00+00:00',
}

describe('offline snapshot', () => {
	it('stores RSA ciphertext as-is and encrypts plaintext metadata at rest', async () => {
		const key = await makeKey()
		const snapshot = await encryptSnapshot(key, MANIFEST)

		// Ciphertext fields copied verbatim (already server-safe).
		expect(snapshot.secrets[0].key).toBe('RSA-CIPHERTEXT-KEY')
		expect(snapshot.secrets[0].login).toBe('RSA-CIPHERTEXT-LOGIN')
		// The suite blob + KDF-bearing envelope preserved.
		expect(snapshot.suite.privateKey).toBe('ENVELOPE-BLOB')

		// Plaintext name/url NOT present anywhere in the at-rest snapshot.
		const serialized = JSON.stringify(snapshot)
		expect(serialized).not.toContain('Prod API key')
		expect(serialized).not.toContain('api.example.gov')
		expect(serialized).not.toContain('Servers')
	})

	it('round-trips back to a readable vault with the right key', async () => {
		const key = await makeKey()
		const snapshot = await encryptSnapshot(key, MANIFEST)
		const vault = await decryptSnapshot(key, snapshot)

		expect(vault.secrets[0].name).toBe('Prod API key')
		expect(vault.secrets[0].url).toBe('https://api.example.gov')
		expect(vault.secrets[0].key).toBe('RSA-CIPHERTEXT-KEY')
		expect(vault.folders[0].name).toBe('Servers')
		expect(vault.syncedAt).toBe('2026-07-20T04:00:00+00:00')
	})

	it('does not open the metadata with the wrong key', async () => {
		const key = await makeKey()
		const other = await makeKey()
		const snapshot = await encryptSnapshot(key, MANIFEST)
		await expect(decryptSnapshot(other, snapshot)).rejects.toThrow()
	})
})
