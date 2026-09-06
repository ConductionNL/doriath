/**
 * @spec openspec/changes/cxp-transfer/specs/cxp-transfer/spec.md
 *
 * HPKE (RFC 9180) base-mode seal/open round-trip and negative cases. Exercises
 * the real WebCrypto (X25519 / HKDF-SHA256 / AES-256-GCM), matching the repo's
 * crypto-spec convention of driving crypto.subtle end to end.
 */
import { describe, expect, it } from 'vitest'
import {
	AEAD_ID,
	generateRecipientKeyPair,
	KDF_ID,
	KEM_ID,
	open,
	seal,
} from '../../src/crypto/hpke.js'

const enc = new TextEncoder()

describe('HPKE base mode', () => {
	it('pins the ecosystem cipher suite', () => {
		expect(KEM_ID).toBe(0x0020)
		expect(KDF_ID).toBe(0x0001)
		expect(AEAD_ID).toBe(0x0002)
	})

	it('seals and opens a payload round-trip', async () => {
		const { privateKey, publicKeyRaw } = await generateRecipientKeyPair()
		const info = enc.encode('keepiq-cxp-v1:ctx')
		const aad = enc.encode('32.1.2')
		const pt = enc.encode(
			'the CXF payload bytes — long enough to matter '.repeat(40),
		)

		const { enc: encapsulated, ciphertext } = await seal(
			publicKeyRaw,
			info,
			aad,
			pt,
		)
		expect(encapsulated.length).toBe(32)
		expect(ciphertext.length).toBe(pt.length + 16) // AES-GCM tag

		const recovered = await open(
			privateKey,
			publicKeyRaw,
			encapsulated,
			info,
			aad,
			ciphertext,
		)
		expect(new TextDecoder().decode(recovered)).toBe(
			new TextDecoder().decode(pt),
		)
	})

	it('fails to open with a mismatched info (context binding)', async () => {
		const { privateKey, publicKeyRaw } = await generateRecipientKeyPair()
		const aad = enc.encode('32.1.2')
		const { enc: e, ciphertext } = await seal(
			publicKeyRaw,
			enc.encode('info-A'),
			aad,
			enc.encode('secret'),
		)
		await expect(
			open(privateKey, publicKeyRaw, e, enc.encode('info-B'), aad, ciphertext),
		).rejects.toBeTruthy()
	})

	it('fails to open with a mismatched aad (suite binding)', async () => {
		const { privateKey, publicKeyRaw } = await generateRecipientKeyPair()
		const info = enc.encode('info')
		const { enc: e, ciphertext } = await seal(
			publicKeyRaw,
			info,
			enc.encode('32.1.2'),
			enc.encode('secret'),
		)
		await expect(
			open(
				privateKey,
				publicKeyRaw,
				e,
				info,
				enc.encode('32.1.1'),
				ciphertext,
			),
		).rejects.toBeTruthy()
	})

	it('fails to open with the wrong recipient key', async () => {
		const a = await generateRecipientKeyPair()
		const b = await generateRecipientKeyPair()
		const info = enc.encode('info')
		const aad = enc.encode('32.1.2')
		const { enc: e, ciphertext } = await seal(
			a.publicKeyRaw,
			info,
			aad,
			enc.encode('secret'),
		)
		// Opening with B's private key against A's ciphertext must fail.
		await expect(
			open(b.privateKey, a.publicKeyRaw, e, info, aad, ciphertext),
		).rejects.toBeTruthy()
	})
})
