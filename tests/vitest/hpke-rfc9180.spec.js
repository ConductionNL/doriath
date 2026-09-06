/**
 * @spec openspec/changes/cxp-transfer/specs/cxp-transfer/spec.md
 *
 * RFC 9180 Appendix A.1 known-answer test for DHKEM(X25519, HKDF-SHA256). This
 * is the interop anchor: it proves our KEM derivation matches the standard
 * (and therefore the shipping CXP ecosystem), not merely that it round-trips
 * with itself. The AEAD differs from A.1 (we use AES-256-GCM, A.1 uses
 * AES-128-GCM), but DHKEM's KEM suite_id depends only on kem_id (0x0020),
 * identical to A.1 — so the shared_secret is directly comparable.
 */
import { describe, expect, it } from 'vitest'
import { _internals } from '../../src/crypto/hpke.js'

function hexToBytes(hex) {
	const out = new Uint8Array(hex.length / 2)
	for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.substr(i * 2, 2), 16)
	return out
}
function bytesToHex(bytes) {
	return Array.from(bytes)
		.map((b) => b.toString(16).padStart(2, '0'))
		.join('')
}

// RFC 9180 §A.1.1 (DHKEM(X25519, HKDF-SHA256))
const A1 = {
	skEm: '52c4a758a802cd8b936eceea314432798d5baf2d7e9235dc084ab1b9cfa2f736',
	pkRm: '3948cfe0ad1ddb695d780e59077195da6c56506b027329794ab02bca80815c4d',
	enc: '37fda3567bdbd628e88668c3c8d7e97d1d1253b6d4ea6d44c150f741f1bf4431',
	sharedSecret: 'fe0e18c9f024ce43799ae393c7e8fe8fce9d218875e8227b0187c04e7d2ea1fc',
}

// Wrap a raw 32-byte X25519 scalar as PKCS#8 (RFC 8410) so WebCrypto can import it.
function x25519Pkcs8(rawScalar) {
	const prefix = hexToBytes('302e020100300506032b656e04220420')
	const out = new Uint8Array(prefix.length + 32)
	out.set(prefix, 0)
	out.set(rawScalar, prefix.length)
	return out
}

describe('HPKE DHKEM — RFC 9180 A.1 known-answer', () => {
	it('derives the RFC A.1 shared_secret', async () => {
		const skEm = await crypto.subtle.importKey(
			'pkcs8',
			x25519Pkcs8(hexToBytes(A1.skEm)),
			{ name: 'X25519' },
			false,
			['deriveBits'],
		)
		const pkR = await crypto.subtle.importKey(
			'raw',
			hexToBytes(A1.pkRm),
			{ name: 'X25519' },
			true,
			[],
		)
		const dh = new Uint8Array(
			await crypto.subtle.deriveBits(
				{ name: 'X25519', public: pkR },
				skEm,
				256,
			),
		)

		const shared = await _internals.deriveSharedSecret(
			dh,
			hexToBytes(A1.enc),
			hexToBytes(A1.pkRm),
		)
		expect(bytesToHex(shared)).toBe(A1.sharedSecret)
	})
})
