/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Tests for at-rest metadata encryption under the vault unlock key
 * (offline-readonly-cache §6.2 / D1).
 */
import { describe, expect, it } from 'vitest'
import { decryptMetadata, encryptMetadata } from '../../src/crypto/metadata.js'

/**
 * A fresh non-extractable AES-256-GCM key (stands in for the vault unlock key).
 *
 * @return {Promise<CryptoKey>}
 */
function makeKey() {
	return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, [
		'encrypt',
		'decrypt',
	])
}

describe('metadata at-rest encryption', () => {
	it('round-trips arbitrary JSON metadata', async () => {
		const key = await makeKey()
		const value = { name: 'prod db password', url: 'https://db.example.gov' }
		const envelope = await encryptMetadata(key, value)
		expect(typeof envelope).toBe('string')
		expect(await decryptMetadata(key, envelope)).toEqual(value)
	})

	it('never stores the plaintext in the envelope', async () => {
		const key = await makeKey()
		const envelope = await encryptMetadata(key, { name: 'SECRET-NAME-XYZ' })
		// Base64 ciphertext must not contain the plaintext substring.
		expect(atob(envelope)).not.toContain('SECRET-NAME-XYZ')
		expect(envelope).not.toContain('SECRET-NAME-XYZ')
	})

	it('uses a fresh IV per call (ciphertexts differ)', async () => {
		const key = await makeKey()
		const a = await encryptMetadata(key, { name: 'same' })
		const b = await encryptMetadata(key, { name: 'same' })
		expect(a).not.toBe(b)
	})

	it('rejects decryption with the wrong key', async () => {
		const key = await makeKey()
		const other = await makeKey()
		const envelope = await encryptMetadata(key, { name: 'x' })
		await expect(decryptMetadata(other, envelope)).rejects.toThrow()
	})

	it('rejects a tampered envelope (GCM auth)', async () => {
		const key = await makeKey()
		const envelope = await encryptMetadata(key, { name: 'x' })
		const bytes = atob(envelope).split('')
		bytes[bytes.length - 1] = String.fromCharCode(
			bytes[bytes.length - 1].charCodeAt(0) ^ 0xff,
		)
		const tampered = btoa(bytes.join(''))
		await expect(decryptMetadata(key, tampered)).rejects.toThrow()
	})
})
