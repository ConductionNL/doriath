/**
 * @spec openspec/changes/browser-extension-autofill/specs/browser-extension-autofill/spec.md
 *
 * The extension shares the web app's crypto verbatim (ADR-003 dual-
 * implementation, task 2.2). This proves the re-exported module round-trips the
 * unlock envelope and RSA-OAEP field encryption, and that the imported private
 * key the worker holds is NON-EXTRACTABLE (task 6.1 invariant).
 */
import { describe, expect, it } from 'vitest'
import {
	decryptPrivateKey,
	deriveAesKey,
	encryptPrivateKey,
	importPrivateKey,
	importPublicKey,
	rsaDecrypt,
	rsaEncrypt,
} from '../../browser-extension/src/crypto/index.js'
import {
	RSA4096_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_PUBLIC_KEY_SPKI_PEM,
} from '../vitest/fixtures/rsa-fixtures.js'

/**
 * The committed RSA-4096 test key pair, as PEM.
 *
 * This used to be a live `crypto.subtle.generateKey({ modulusLength: 4096 })`
 * per test — four unbounded random prime searches on the timed path against
 * vitest's 5000ms per-test default. That is the same construct that timed out
 * `tests/vitest/emergencyEnvelope.spec.js` in run 31083918823. The committed
 * pair is real 4096-bit RSA, so every encrypt/decrypt/import assertion below
 * runs against genuine crypto — only the nondeterministic keygen is gone.
 *
 * @return {{pkcs8: string, spki: string}} PKCS#8 private and SPKI public PEM.
 */
function rsaKeyPairPems() {
	return {
		pkcs8: RSA4096_PRIVATE_KEY_PKCS8_PEM,
		spki: RSA4096_PUBLIC_KEY_SPKI_PEM,
	}
}

describe('extension shared crypto', () => {
	it('round-trips the unlock envelope (encrypt/decrypt private key)', async () => {
		const { pkcs8 } = rsaKeyPairPems()
		const master = 'correct horse battery staple'
		const envelope = await encryptPrivateKey(pkcs8, master)
		const recovered = await decryptPrivateKey(envelope, master)
		expect(recovered.replace(/\s+/g, '')).toBe(pkcs8.replace(/\s+/g, ''))
	})

	it('fails to unlock with the wrong master password', async () => {
		const { pkcs8 } = rsaKeyPairPems()
		const envelope = await encryptPrivateKey(pkcs8, 'right')
		await expect(decryptPrivateKey(envelope, 'wrong')).rejects.toBeTruthy()
	})

	it('imports the private key NON-EXTRACTABLE (worker key never leaves)', async () => {
		const { pkcs8 } = rsaKeyPairPems()
		const key = await importPrivateKey(pkcs8)
		expect(key.extractable).toBe(false)
	})

	it('round-trips a field: encrypt to the cert, decrypt with the private key', async () => {
		const { pkcs8, spki } = rsaKeyPairPems()
		const publicKey = await importPublicKey(spki)
		const privateKey = await importPrivateKey(pkcs8)
		const value =
			'S3cr3t value — long enough to force multiple RSA-OAEP chunks: '
			+ 'x'.repeat(700)
		const ciphertext = await rsaEncrypt(value, publicKey)
		const recovered = await rsaDecrypt(ciphertext, privateKey)
		expect(recovered).toBe(value)
	})

	it('deriveAesKey produces a non-extractable AES key', async () => {
		const key = await deriveAesKey('pw', new Uint8Array(16))
		expect(key.extractable).toBe(false)
	})
})
