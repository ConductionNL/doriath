/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Tests for passkey PRF vault-unlock crypto (passkey-vault-login
 * §5.2/§5.3): deterministic HKDF KEK, wrap→unwrap round-trip,
 * wrong-PRF rejection, and feature detection.
 */
import { describe, expect, it } from 'vitest'
import {
	decryptPrivateKeyWithRawKey,
	deriveUnlockKeyRaw,
	encryptPrivateKey,
} from '../../src/crypto/aes.js'
import { decodeEnvelope } from '../../src/crypto/envelope.js'
import {
	deriveKekFromPrf,
	fromBase64Url,
	isPrfSupported,
	toBase64Url,
	unwrapUnlockKey,
	wrapUnlockKey,
} from '../../src/crypto/passkey.js'

const PRF_A = new Uint8Array(32).fill(7)
const PRF_B = new Uint8Array(32).fill(9)
const CRED = 'credential-id-abc'

describe('passkey PRF crypto', () => {
	it('wrap→unwrap round-trips the raw unlock key', async () => {
		const unlockKey = crypto.getRandomValues(new Uint8Array(32))
		const kek = await deriveKekFromPrf(PRF_A, CRED)
		const envelope = await wrapUnlockKey(kek, unlockKey)
		const kek2 = await deriveKekFromPrf(PRF_A, CRED)
		const recovered = await unwrapUnlockKey(kek2, envelope)
		expect(Array.from(recovered)).toEqual(Array.from(unlockKey))
	})

	it('a different PRF output cannot unwrap the envelope', async () => {
		const unlockKey = crypto.getRandomValues(new Uint8Array(32))
		const kek = await deriveKekFromPrf(PRF_A, CRED)
		const envelope = await wrapUnlockKey(kek, unlockKey)
		const wrongKek = await deriveKekFromPrf(PRF_B, CRED)
		await expect(unwrapUnlockKey(wrongKek, envelope)).rejects.toThrow()
	})

	it('the credential id salts the KEK (different cred → different KEK)', async () => {
		const unlockKey = crypto.getRandomValues(new Uint8Array(32))
		const kek = await deriveKekFromPrf(PRF_A, CRED)
		const envelope = await wrapUnlockKey(kek, unlockKey)
		const otherCredKek = await deriveKekFromPrf(PRF_A, 'a-different-credential')
		await expect(unwrapUnlockKey(otherCredKek, envelope)).rejects.toThrow()
	})

	it('uses a fresh IV per wrap (envelopes differ)', async () => {
		const unlockKey = crypto.getRandomValues(new Uint8Array(32))
		const kek = await deriveKekFromPrf(PRF_A, CRED)
		const a = await wrapUnlockKey(kek, unlockKey)
		const b = await wrapUnlockKey(kek, unlockKey)
		expect(a).not.toBe(b)
	})

	it('the PRF-recovered raw key decrypts the same private-key blob as the master password', async () => {
		// End-to-end: wrap the master-derived unlock key under PRF, unwrap it,
		// and confirm it opens the private-key envelope identically.
		const pem =
			'-----BEGIN PRIVATE KEY-----\nMOCKKEYDATA\n-----END PRIVATE KEY-----'
		const password = 'TfMaster2026!vault'
		const blob = await encryptPrivateKey(pem, password)
		const { salt } = decodeEnvelope(blob)
		const rawUnlockKey = await deriveUnlockKeyRaw(password, salt)

		const kek = await deriveKekFromPrf(PRF_A, CRED)
		const wrapped = await wrapUnlockKey(kek, rawUnlockKey)
		const recovered = await unwrapUnlockKey(
			await deriveKekFromPrf(PRF_A, CRED),
			wrapped,
		)

		const decrypted = await decryptPrivateKeyWithRawKey(blob, recovered)
		expect(decrypted).toBe(pem)
	})

	it('base64url round-trips WebAuthn ids', () => {
		const bytes = crypto.getRandomValues(new Uint8Array(20))
		const encoded = toBase64Url(bytes.buffer)
		expect(encoded).not.toMatch(/[+/=]/)
		expect(Array.from(fromBase64Url(encoded))).toEqual(Array.from(bytes))
	})

	it('isPrfSupported is false without PublicKeyCredential', () => {
		expect(isPrfSupported()).toBe(false)
	})
})
