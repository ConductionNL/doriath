/**
 * @spec openspec/changes/extension-passkey-provider/specs/extension-passkey-provider/spec.md
 *
 * CBOR encoder + DER conversion used to build the WebAuthn attestation object
 * and COSE key. Verified against canonical byte sequences from RFC 8949 (CBOR)
 * so the attestation the extension emits is standards-correct, not merely
 * self-consistent.
 */
import { describe, expect, it } from 'vitest'
import {
	cborEncode,
	rawEcdsaToDer,
} from '../../browser-extension/src/passkey/cbor.js'

function hex(u8) {
	return Array.from(u8)
		.map((b) => b.toString(16).padStart(2, '0'))
		.join('')
}

describe('CBOR encoder (RFC 8949 vectors)', () => {
	it('encodes small unsigned ints', () => {
		expect(hex(cborEncode(0))).toBe('00')
		expect(hex(cborEncode(1))).toBe('01')
		expect(hex(cborEncode(10))).toBe('0a')
		expect(hex(cborEncode(23))).toBe('17')
		expect(hex(cborEncode(24))).toBe('1818')
		expect(hex(cborEncode(100))).toBe('1864')
		expect(hex(cborEncode(1000))).toBe('1903e8')
	})

	it('encodes negative ints (COSE alg -7 = 0x26)', () => {
		expect(hex(cborEncode(-1))).toBe('20')
		expect(hex(cborEncode(-7))).toBe('26') // ES256 alg id in the COSE key
		expect(hex(cborEncode(-10))).toBe('29')
	})

	it('encodes text strings', () => {
		expect(hex(cborEncode('a'))).toBe('6161')
		expect(hex(cborEncode('IETF'))).toBe('6449455446')
	})

	it('encodes byte strings', () => {
		expect(hex(cborEncode(Uint8Array.of(1, 2, 3, 4)))).toBe('4401020304')
	})

	it('encodes maps preserving key order (COSE-style with int keys)', () => {
		const m = new Map()
		m.set(1, 2)
		m.set(3, -7)
		expect(hex(cborEncode(m))).toBe('a201020326')
	})

	it('encodes the attestation object skeleton (fmt none)', () => {
		const att = new Map()
		att.set('fmt', 'none')
		att.set('attStmt', new Map())
		att.set('authData', Uint8Array.of(0xaa, 0xbb))
		const out = hex(cborEncode(att))
		// map(3) + "fmt":"none" + "attStmt":{} + "authData":h'aabb'
		expect(out).toBe(
			'a3'
				+ '63666d74'
				+ '646e6f6e65'
				+ '676174745374'
				+ '6d74'
				+ 'a0'
				+ '68617574684461'
				+ '7461'
				+ '42aabb',
		)
	})
})

describe('raw ECDSA → DER', () => {
	it('wraps r,s in a SEQUENCE of INTEGERs with high-bit padding', () => {
		const raw = new Uint8Array(64)
		raw[0] = 0x80 // r high bit set → needs a 0x00 pad
		raw[32] = 0x01 // s small
		const der = rawEcdsaToDer(raw)
		expect(der[0]).toBe(0x30) // SEQUENCE
		expect(der[2]).toBe(0x02) // INTEGER (r)
		// r is 32 bytes with high bit → 33-byte INTEGER (leading 0x00)
		expect(der[3]).toBe(0x21)
		expect(der[4]).toBe(0x00)
	})
})
