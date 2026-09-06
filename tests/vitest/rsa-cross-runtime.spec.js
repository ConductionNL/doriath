/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Cross-runtime RSA contract test for the secret-request fill-in flow.
 *
 * Locks the implement-secret-requests §13.5 invariant: a value encrypted
 * in the browser via WebCrypto RSA-OAEP-SHA256 (`rsaEncrypt`) MUST be
 * decryptable by the PHP-side OpenSSL path that EncryptService uses on
 * the server.
 *
 * The PHP side talks to OpenSSL via `openssl_private_decrypt(..., $key,
 * OPENSSL_PKCS1_OAEP_PADDING)` with SHA-256 bound via the same
 * underlying libcrypto. Both Node's `crypto` and PHP's `openssl_*`
 * functions are thin wrappers over OpenSSL; the bytes they emit and
 * accept for RSA-OAEP(SHA-256) are interchangeable when the key, the
 * label (none), and the MGF1 hash (SHA-256) line up. We use Node's
 * `crypto.privateDecrypt` as a faithful PHP-OpenSSL proxy here — that is
 * the same approach the OpenSSL upstream test-vectors take, and it
 * means the cross-runtime contract test can run inside vitest without
 * shelling out to PHP. The pure-PHP half of the contract is locked
 * separately by `tests/Unit/Service/EncryptServiceTest.php` (PHP
 * decrypt of a PHP-produced ciphertext) — between the two specs every
 * edge of the wire format is asserted.
 *
 * The chunked wire format produced by `src/crypto/rsa.js#rsaEncrypt`:
 *
 *   [4-byte big-endian chunk-count][512-byte RSA-OAEP block]*
 *
 * RSA-4096 fixed-block size (512 bytes) per chunk; plaintext is split
 * into 446-byte chunks before encryption (OAEP-SHA256 with a 4096-bit
 * key admits 446 plaintext bytes per block). Each block is decoded
 * independently with OpenSSL and the bytes are reassembled to recover
 * the original UTF-8.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#task-13.5
 */

import {
	constants,
	createPrivateKey,
	generateKeyPairSync,
	privateDecrypt,
} from 'node:crypto'
import { beforeAll, describe, expect, it } from 'vitest'
import { importPublicKey, rsaEncrypt } from '../../src/crypto/rsa.js'

const RSA_BLOCK_SIZE = 512

let publicKeyPem
let privateKeyPem

beforeAll(() => {
	// One 4096-bit keypair per spec run — RSA-4096 is the format the
	// JS-side rsaEncrypt is built for (fixed 512-byte block size).
	const { publicKey, privateKey } = generateKeyPairSync('rsa', {
		modulusLength: 4096,
		publicKeyEncoding: { type: 'spki', format: 'pem' },
		privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
	})
	publicKeyPem = publicKey
	privateKeyPem = privateKey
})

/**
 * Decode the chunked wire format produced by rsaEncrypt and decrypt
 * each block via Node's OpenSSL binding (the PHP-side proxy). Returns
 * the recovered UTF-8 plaintext.
 *
 * @param {string} ciphertextB64 base64-encoded chunked ciphertext
 * @param {string} privateKeyPem the PKCS#8 private key PEM
 * @return {string} the recovered plaintext
 */
function decryptViaOpenssl(ciphertextB64, privateKeyPem) {
	const raw = Buffer.from(ciphertextB64, 'base64')
	const view = new DataView(raw.buffer, raw.byteOffset, raw.byteLength)
	const chunkCount = view.getUint32(0, false)

	const key = createPrivateKey({ key: privateKeyPem, format: 'pem' })

	const recovered = []
	for (let i = 0; i < chunkCount; i++) {
		const block = raw.subarray(
			4 + i * RSA_BLOCK_SIZE,
			4 + (i + 1) * RSA_BLOCK_SIZE,
		)
		const plain = privateDecrypt(
			{
				key,
				padding: constants.RSA_PKCS1_OAEP_PADDING,
				oaepHash: 'sha256',
			},
			block,
		)
		recovered.push(plain)
	}
	return Buffer.concat(recovered).toString('utf8')
}

describe('rsa cross-runtime (JS WebCrypto -> OpenSSL)', () => {
	it('OpenSSL decrypts a WebCrypto RSA-OAEP-SHA256 ciphertext (short value, single chunk)', async () => {
		const plaintext = 'ghp_AAA1234567890'

		const jsPub = await importPublicKey(publicKeyPem)
		const ciphertextB64 = await rsaEncrypt(plaintext, jsPub)

		expect(typeof ciphertextB64).toBe('string')
		expect(ciphertextB64.length).toBeGreaterThan(0)

		const recovered = decryptViaOpenssl(ciphertextB64, privateKeyPem)
		expect(recovered).toBe(plaintext)
	})

	it('OpenSSL decrypts WebCrypto ciphertext for the empty string (boundary)', async () => {
		const jsPub = await importPublicKey(publicKeyPem)
		const ciphertextB64 = await rsaEncrypt('', jsPub)
		expect(decryptViaOpenssl(ciphertextB64, privateKeyPem)).toBe('')
	})

	it('OpenSSL decrypts WebCrypto ciphertext containing UTF-8 (non-ASCII)', async () => {
		// Mixes diacritics, CJK, and an emoji — pins that the JS-side
		// rsaEncrypt encodes via TextEncoder('utf-8') and the OpenSSL-side
		// decrypt returns the SAME UTF-8 bytes (no double-encode, no
		// mojibake).
		const plaintext = 'müller — 北京 — 🔐'

		const jsPub = await importPublicKey(publicKeyPem)
		const ciphertextB64 = await rsaEncrypt(plaintext, jsPub)
		expect(decryptViaOpenssl(ciphertextB64, privateKeyPem)).toBe(plaintext)
	})

	it('OpenSSL decrypts WebCrypto ciphertext across the multi-chunk boundary (>= 446 bytes)', async () => {
		// 1500-byte ASCII payload — covers the 4-block chunk path (446
		// chunk size + 4-byte header + 4 * 512 RSA-OAEP blocks).
		const plaintext = 'A'.repeat(1500)

		const jsPub = await importPublicKey(publicKeyPem)
		const ciphertextB64 = await rsaEncrypt(plaintext, jsPub)

		// Verify the wire shape: header says 4 chunks (ceil(1500 / 446) = 4)
		// and the total raw length matches the format equation.
		const raw = Buffer.from(ciphertextB64, 'base64')
		const view = new DataView(raw.buffer, raw.byteOffset, raw.byteLength)
		expect(view.getUint32(0, false)).toBe(4)
		expect(raw.length).toBe(4 + 4 * RSA_BLOCK_SIZE)

		expect(decryptViaOpenssl(ciphertextB64, privateKeyPem)).toBe(plaintext)
	})
})
