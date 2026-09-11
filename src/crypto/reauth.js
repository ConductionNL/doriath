/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Client-side master-password re-authentication (secret-export-gdpr D2).
 *
 * Under the always-E2E model (ADR-003) the server never sees the master
 * password and cannot verify it, so a "re-auth" gate is a CLIENT-SIDE proof of
 * knowledge: the entered password is run through the normal AES key-derivation
 * and used to attempt decryption of the stored private-key blob (re-fetched from
 * the API). Only if that succeeds did the user know the master password.
 *
 * Critically, this NEVER replaces the session CryptoKey and discards every
 * derived key immediately. It blocks the unattended-unlocked-session ("lunch
 * break") attack without weakening any E2E guarantee. The control is advisory
 * against a tampered client — true of every client-side control under E2E,
 * stated openly in the spec.
 */

import { decryptPrivateKey } from './aes.js'

/**
 * The exact string the server verifies (VaultKeyProofService::signedMessage):
 * the challenge, then the lowercase-hex SHA-256 of each bound value in the
 * declared order, one per line. Only named scalar values cross the boundary, so
 * no JSON-canonicalisation agreement is needed.
 *
 * @param {string} nonce The server-issued challenge.
 * @param {string[]} boundValues The bound request-parameter values, in order.
 * @return {Promise<Uint8Array>} The UTF-8 bytes of the message to sign.
 */
async function signedMessageBytes(nonce, boundValues) {
	const encoder = new TextEncoder()
	const lines = [nonce]
	for (const value of boundValues) {
		const digest = await crypto.subtle.digest(
			'SHA-256',
			encoder.encode(String(value ?? '')),
		)
		const hex = Array.from(new Uint8Array(digest))
			.map((b) => b.toString(16).padStart(2, '0'))
			.join('')
		lines.push(hex)
	}
	return encoder.encode(lines.join('\n'))
}

/**
 * Produce a VaultKeyProof: a signature over a server-issued challenge, made
 * with the suite private key, proving master-password knowledge to the server.
 *
 * This is a SIGNATURE, never a decryption — the session key is decrypt-only, so
 * only a key re-derived from the freshly entered master password can sign, which
 * is exactly the guarantee the server relies on. The derived AES key, the PEM,
 * and the signing key are all discarded the moment signing resolves; nothing is
 * returned but the signature, and nothing is stored.
 *
 * @param {string} encryptedPrivateKey The stored AES envelope for the subject suite.
 * @param {string} masterPassword The freshly entered master password.
 * @param {string} nonce The challenge from GET /suites/{id}/proof-challenge.
 * @param {string[]} boundValues The request parameters the proof commits to, in order.
 * @return {Promise<string>} The base64 signature to send as X-Keepiq-Key-Proof.
 */
export async function proveMasterPassword(
	encryptedPrivateKey,
	masterPassword,
	nonce,
	boundValues = [],
) {
	// Decrypt the envelope with the master password to recover the PKCS#8 PEM.
	// A wrong password throws here (AES-GCM tag mismatch) before anything signs.
	const pem = await decryptPrivateKey(encryptedPrivateKey, masterPassword)

	const pkcs8 = Uint8Array.from(
		atob(
			pem
				.replace(/-----BEGIN PRIVATE KEY-----/, '')
				.replace(/-----END PRIVATE KEY-----/, '')
				.replace(/-----BEGIN RSA PRIVATE KEY-----/, '')
				.replace(/-----END RSA PRIVATE KEY-----/, '')
				.replace(/\s/g, ''),
		),
		(c) => c.charCodeAt(0),
	)

	// Re-import for SIGNING specifically — a distinct capability from the
	// session key, which is imported non-extractable and ['decrypt'] only.
	const signingKey = await crypto.subtle.importKey(
		'pkcs8',
		pkcs8,
		{ name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
		false,
		['sign'],
	)

	const message = await signedMessageBytes(nonce, boundValues)
	const signature = await crypto.subtle.sign(
		'RSASSA-PKCS1-v1_5',
		signingKey,
		message,
	)

	return btoa(String.fromCharCode(...new Uint8Array(signature)))
}

/**
 * Verify the master password by attempting to decrypt the private-key blob.
 *
 * The freshly derived key is discarded the moment decryption resolves/rejects;
 * the caller's session CryptoKey is untouched. The password is never sent to
 * the server.
 *
 * @param {string} encryptedPrivateKey The stored AES envelope (private-key blob)
 * @param {string} masterPassword The freshly entered master password
 * @return {Promise<boolean>} True if the password decrypts the blob, else false
 */
export async function verifyMasterPassword(encryptedPrivateKey, masterPassword) {
	if (
		!encryptedPrivateKey
		|| typeof masterPassword !== 'string'
		|| masterPassword.length === 0
	) {
		return false
	}
	try {
		// A successful decrypt (AES-GCM tag verifies) proves knowledge of the
		// password. The returned PEM is intentionally not retained or imported.
		await decryptPrivateKey(encryptedPrivateKey, masterPassword)
		return true
	} catch {
		// Wrong password => AES-GCM authentication-tag mismatch => throw.
		return false
	}
}
