/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Round-trip tests for the AES-256-GCM master-password envelope in
 * `src/crypto/aes.js` + `src/crypto/envelope.js`.
 *
 * Keepiq wraps the user's RSA private key with AES-256-GCM whose key is
 * derived from the master password via PBKDF2-SHA256 (600k iterations).
 * The wrapped blob lives in `oc_doriath_settings` and the user only ever
 * unwraps it client-side during unlock. The envelope format is:
 *
 *   [4B version][16B salt][12B IV][N B ciphertext][16B GCM tag]   (base64)
 *
 * What these tests lock down (the master-password recovery contract):
 *  - `encryptPrivateKey` + `decryptPrivateKey` round-trip a PKCS#8 PEM
 *    exactly (byte-for-byte).
 *  - The envelope embeds a fresh salt + IV per encryption — two
 *    encryptions of the SAME plaintext with the SAME password produce
 *    DIFFERENT ciphertexts (no nonce reuse).
 *  - A wrong master password fails decryption with a recognisable error
 *    (the GCM tag rejects authentication).
 *  - A tampered ciphertext byte fails authentication (GCM authenticity
 *    guarantees the AES envelope is integrity-protected, not just
 *    confidential).
 *  - The envelope header (version, salt, IV) decodes back to the
 *    components the encrypt path produced (round-trip via decodeEnvelope).
 *  - UTF-8 payloads with multi-byte characters survive the round-trip.
 *
 * Running in the `node` env — WebCrypto AES-GCM + PBKDF2 ship with
 * Node 20+ on globalThis.crypto.subtle, no jsdom needed.
 *
 * @spec openspec/changes/implement-secrets/tasks.md#13
 */

import { describe, expect, it } from 'vitest'
import { decryptPrivateKey, encryptPrivateKey } from '../../src/crypto/aes.js'
import {
	decodeEnvelope,
	ENVELOPE_VERSION,
	IV_LENGTH,
	SALT_LENGTH,
} from '../../src/crypto/envelope.js'

const SAMPLE_PEM =
	'-----BEGIN PRIVATE KEY-----\n'
	+ 'MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQC2Yp1bZk3Yp1bZ\n'
	+ 'k3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk\n'
	+ '3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3Yp1bZk3\n'
	+ '-----END PRIVATE KEY-----'

describe('AES-GCM master-password envelope round-trip', () => {
	it('encrypts then decrypts a PEM plaintext back to the original bytes', async () => {
		const password = 'correct-horse-battery-staple'

		const envelope = await encryptPrivateKey(SAMPLE_PEM, password)
		expect(typeof envelope).toBe('string')
		expect(envelope.length).toBeGreaterThan(0)

		const recovered = await decryptPrivateKey(envelope, password)
		expect(recovered).toBe(SAMPLE_PEM)
	})

	it('round-trips an empty plaintext (boundary case)', async () => {
		const envelope = await encryptPrivateKey('', 'pw')
		const recovered = await decryptPrivateKey(envelope, 'pw')
		expect(recovered).toBe('')
	})

	it('round-trips a UTF-8 plaintext with multi-byte characters', async () => {
		const plaintext = 'wachtwoord-€-ü-日本語-🔐'
		const envelope = await encryptPrivateKey(plaintext, 'pw')
		const recovered = await decryptPrivateKey(envelope, 'pw')
		expect(recovered).toBe(plaintext)
	})

	it('produces DIFFERENT ciphertext on two encryptions of the same plaintext+password (fresh salt + IV)', async () => {
		const a = await encryptPrivateKey(SAMPLE_PEM, 'pw')
		const b = await encryptPrivateKey(SAMPLE_PEM, 'pw')
		expect(a).not.toBe(b)

		// And both still decrypt to the same plaintext.
		expect(await decryptPrivateKey(a, 'pw')).toBe(SAMPLE_PEM)
		expect(await decryptPrivateKey(b, 'pw')).toBe(SAMPLE_PEM)
	})
})

describe('AES-GCM master-password envelope — authenticity guarantees', () => {
	it('rejects decryption with the wrong master password (GCM tag mismatch)', async () => {
		const envelope = await encryptPrivateKey(SAMPLE_PEM, 'correct-pw')
		await expect(decryptPrivateKey(envelope, 'wrong-pw')).rejects.toThrow()
	})

	it('rejects a tampered ciphertext byte (GCM authenticity)', async () => {
		const envelope = await encryptPrivateKey(SAMPLE_PEM, 'pw')

		// Flip one byte deep inside the ciphertext body (past the header).
		const raw = Uint8Array.from(atob(envelope), (c) => c.charCodeAt(0))
		const tamperIndex = 4 + SALT_LENGTH + IV_LENGTH + 1
		raw[tamperIndex] ^= 0x01
		const tampered = btoa(String.fromCharCode(...raw))

		await expect(decryptPrivateKey(tampered, 'pw')).rejects.toThrow()
	})

	it('rejects a tampered GCM tag (last byte flip)', async () => {
		const envelope = await encryptPrivateKey(SAMPLE_PEM, 'pw')
		const raw = Uint8Array.from(atob(envelope), (c) => c.charCodeAt(0))
		raw[raw.length - 1] ^= 0x01
		const tampered = btoa(String.fromCharCode(...raw))
		await expect(decryptPrivateKey(tampered, 'pw')).rejects.toThrow()
	})

	it('rejects a truncated envelope (header-only)', async () => {
		// Just version + salt + IV + no ciphertext body or tag.
		const raw = new Uint8Array(4 + SALT_LENGTH + IV_LENGTH)
		const truncated = btoa(String.fromCharCode(...raw))
		await expect(decryptPrivateKey(truncated, 'pw')).rejects.toThrow()
	})
})

describe('envelope.decodeEnvelope', () => {
	it('decodes an encrypt-side envelope into version/salt/IV/ciphertext-with-tag', async () => {
		const envelope = await encryptPrivateKey(SAMPLE_PEM, 'pw')
		const decoded = decodeEnvelope(envelope)

		expect(decoded.version).toBe(ENVELOPE_VERSION)
		expect(decoded.salt).toBeInstanceOf(Uint8Array)
		expect(decoded.salt.byteLength).toBe(SALT_LENGTH)
		expect(decoded.iv).toBeInstanceOf(Uint8Array)
		expect(decoded.iv.byteLength).toBe(IV_LENGTH)
		// ciphertext-with-tag = ciphertext body + 16-byte tag, never empty.
		expect(decoded.ciphertextWithTag).toBeInstanceOf(Uint8Array)
		expect(decoded.ciphertextWithTag.byteLength).toBeGreaterThanOrEqual(16)
	})

	it('throws "Envelope too short" when the blob is below the minimum size', () => {
		const tiny = btoa(String.fromCharCode(0x00, 0x00, 0x00, 0x01))
		expect(() => decodeEnvelope(tiny)).toThrow(/too short/i)
	})

	it('throws "Unsupported envelope version" when the version byte is unknown', () => {
		// Build a header with version=99 + zero salt/IV + 16-byte fake tag.
		const bytes = new Uint8Array(4 + SALT_LENGTH + IV_LENGTH + 16)
		const view = new DataView(bytes.buffer)
		view.setUint32(0, 99, false)
		const blob = btoa(String.fromCharCode(...bytes))
		expect(() => decodeEnvelope(blob)).toThrow(/unsupported envelope version/i)
	})
})
