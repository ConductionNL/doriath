/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Tests for proveMasterPassword — the client half of the vault-key proof. A
 * signature it produces must verify, under RSASSA-PKCS1-v1_5 SHA-256, against
 * the suite public key over the exact message the server rebuilds (challenge +
 * per-bound-value SHA-256). A wrong password must throw before signing, and a
 * changed bound value must make the signature fail — the binding the server
 * relies on. Also pins that the SESSION key stays decrypt-only, so it can never
 * be the thing that signs.
 */

import { describe, expect, it } from 'vitest'
import { encryptPrivateKey } from '../../src/crypto/aes.js'
import { proveMasterPassword } from '../../src/crypto/reauth.js'
import { importPrivateKey } from '../../src/crypto/rsa.js'

/**
 * Generate an RSA keypair and return the private key as PKCS#8 PEM plus the
 * public key as a verify-capable CryptoKey.
 *
 * @return {Promise<{privateKeyPem: string, publicKey: CryptoKey}>} The pair.
 */
async function makePair() {
	const pair = await crypto.subtle.generateKey(
		{
			name: 'RSA-PSS',
			modulusLength: 2048,
			publicExponent: new Uint8Array([1, 0, 1]),
			hash: 'SHA-256',
		},
		true,
		['sign', 'verify'],
	)
	const pkcs8 = new Uint8Array(
		await crypto.subtle.exportKey('pkcs8', pair.privateKey),
	)
	const privateKeyPem =
		'-----BEGIN PRIVATE KEY-----\n'
		+ btoa(String.fromCharCode(...pkcs8))
			.match(/.{1,64}/g)
			.join('\n')
		+ '\n-----END PRIVATE KEY-----'

	// Re-import the public key for the RSASSA-PKCS1-v1_5 scheme the proof uses.
	const spki = new Uint8Array(
		await crypto.subtle.exportKey('spki', pair.publicKey),
	)
	const publicKey = await crypto.subtle.importKey(
		'spki',
		spki,
		{ name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
		false,
		['verify'],
	)

	return { privateKeyPem, publicKey }
}

/**
 * Rebuild the signed message exactly as VaultKeyProofService::signedMessage:
 * challenge, then hex SHA-256 of each bound value, one per line.
 *
 * @param {string} nonce The challenge.
 * @param {string[]} boundValues The bound values, in order.
 * @return {Promise<Uint8Array>} The message bytes.
 */
async function rebuildMessage(nonce, boundValues) {
	const enc = new TextEncoder()
	const lines = [nonce]
	for (const v of boundValues) {
		const d = await crypto.subtle.digest('SHA-256', enc.encode(String(v)))
		lines.push(
			Array.from(new Uint8Array(d))
				.map((b) => b.toString(16).padStart(2, '0'))
				.join(''),
		)
	}
	return enc.encode(lines.join('\n'))
}

describe('proveMasterPassword', () => {
	it('produces a signature that verifies over the bound message', async () => {
		const { privateKeyPem, publicKey } = await makePair()
		const envelope = await encryptPrivateKey(privateKeyPem, 'master-pw')
		const nonce = 'challenge.mac'
		const bound = ['PUBLIC', 'ENVELOPE']

		const sigB64 = await proveMasterPassword(envelope, 'master-pw', nonce, bound)
		const signature = Uint8Array.from(atob(sigB64), (c) => c.charCodeAt(0))

		const ok = await crypto.subtle.verify(
			'RSASSA-PKCS1-v1_5',
			publicKey,
			signature,
			await rebuildMessage(nonce, bound),
		)
		expect(ok).toBe(true)
	})

	it('throws on a wrong password, before any signature is produced', async () => {
		const { privateKeyPem } = await makePair()
		const envelope = await encryptPrivateKey(privateKeyPem, 'master-pw')

		await expect(
			proveMasterPassword(envelope, 'WRONG', 'challenge.mac', []),
		).rejects.toBeDefined()
	})

	it('binds to the values: a changed bound value fails verification', async () => {
		const { privateKeyPem, publicKey } = await makePair()
		const envelope = await encryptPrivateKey(privateKeyPem, 'master-pw')
		const nonce = 'challenge.mac'

		const sigB64 = await proveMasterPassword(envelope, 'master-pw', nonce, ['A'])
		const signature = Uint8Array.from(atob(sigB64), (c) => c.charCodeAt(0))

		const ok = await crypto.subtle.verify(
			'RSASSA-PKCS1-v1_5',
			publicKey,
			signature,
			await rebuildMessage(nonce, ['TAMPERED']),
		)
		expect(ok).toBe(false)
	})
})

describe('the session key cannot sign', () => {
	it('imports the session private key non-extractable and decrypt-only', async () => {
		const { privateKeyPem } = await makePair()
		const sessionKey = await importPrivateKey(privateKeyPem)

		expect(sessionKey.extractable).toBe(false)
		expect(sessionKey.usages).toEqual(['decrypt'])
		expect(sessionKey.usages).not.toContain('sign')
	})
})
