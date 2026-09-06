/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for the client-side TOTP parser + RFC 6238 code generator.
 *
 * Asserts the published RFC 6238 test vectors, otpauth:// parsing variants, the
 * bare-base32 default path, the invalid-seed error branch (never a fabricated
 * code), and the no-leak contract (the generator touches no network and no Web
 * Storage API).
 *
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	base32Decode,
	generateTotp,
	parseOtpauth,
	secondsRemaining,
	TOTP_DEFAULTS,
} from '../../src/totp/totp.js'

// RFC 6238 Appendix B — the SHA1 seed is ASCII "12345678901234567890" (20
// bytes); its base32 encoding is the widely published value below. Each vector
// is (unix time seconds -> 8-digit code) for SHA1, period 30.
const SHA1_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
const SHA1_VECTORS = [
	[59, '94287082'],
	[1111111109, '07081804'],
	[1111111111, '14050471'],
	[1234567890, '89005924'],
	[2000000000, '69279037'],
	[20000000000, '65353130'],
]

describe('base32Decode', () => {
	it('decodes the RFC 6238 SHA1 seed to the ASCII bytes', () => {
		const bytes = base32Decode(SHA1_SECRET_BASE32)
		expect(new TextDecoder().decode(bytes)).toBe('12345678901234567890')
	})

	it('ignores whitespace and padding', () => {
		const a = base32Decode('JBSWY3DPEHPK3PXP')
		const b = base32Decode('jbsw y3dp ehpk 3pxp====')
		expect(Array.from(a)).toEqual(Array.from(b))
	})

	it('rejects a non-base32 character', () => {
		expect(() => base32Decode('JBSWY3DP1')).toThrow()
	})

	it('rejects an empty secret', () => {
		expect(() => base32Decode('')).toThrow()
	})
})

describe('parseOtpauth', () => {
	it('parses a bare base32 secret with RFC 6238 defaults', () => {
		const params = parseOtpauth(SHA1_SECRET_BASE32)
		expect(params.secret).toBe(SHA1_SECRET_BASE32)
		expect(params.algorithm).toBe(TOTP_DEFAULTS.algorithm)
		expect(params.digits).toBe(6)
		expect(params.period).toBe(30)
	})

	it('parses a full otpauth URI with issuer/account/algorithm/digits/period', () => {
		const uri =
			'otpauth://totp/ACME%20Co:alice@acme.com?secret='
			+ SHA1_SECRET_BASE32
			+ '&issuer=ACME%20Co&algorithm=SHA256&digits=8&period=60'
		const params = parseOtpauth(uri)
		expect(params.secret).toBe(SHA1_SECRET_BASE32)
		expect(params.issuer).toBe('ACME Co')
		expect(params.account).toBe('alice@acme.com')
		expect(params.algorithm).toBe('SHA256')
		expect(params.digits).toBe(8)
		expect(params.period).toBe(60)
	})

	it('applies defaults for omitted URI params', () => {
		const params = parseOtpauth(
			'otpauth://totp/Example?secret=' + SHA1_SECRET_BASE32,
		)
		expect(params.algorithm).toBe('SHA1')
		expect(params.digits).toBe(6)
		expect(params.period).toBe(30)
	})

	it('rejects a non-totp otpauth URI (e.g. HOTP)', () => {
		expect(() =>
			parseOtpauth(
				'otpauth://hotp/Example?secret=' + SHA1_SECRET_BASE32 + '&counter=1',
			),
		).toThrow()
	})

	it('rejects an otpauth URI with no secret', () => {
		expect(() => parseOtpauth('otpauth://totp/Example?issuer=ACME')).toThrow()
	})

	it('rejects a garbage seed (never returns params)', () => {
		expect(() => parseOtpauth('not a valid seed !!!')).toThrow()
		expect(() => parseOtpauth('')).toThrow()
	})
})

describe('generateTotp — RFC 6238 SHA1 vectors', () => {
	it.each(SHA1_VECTORS)('time %i s -> %s', async (timeSeconds, expected) => {
		const params = {
			secret: SHA1_SECRET_BASE32,
			algorithm: 'SHA1',
			digits: 8,
			period: 30,
		}
		const code = await generateTotp(params, timeSeconds * 1000)
		expect(code).toBe(expected)
	})

	it('produces a zero-padded 6-digit code by default', async () => {
		const params = parseOtpauth(SHA1_SECRET_BASE32)
		const code = await generateTotp(params, 59 * 1000)
		// The 6-digit truncation of the 8-digit "94287082" vector.
		expect(code).toBe('287082')
		expect(code).toHaveLength(6)
	})
})

describe('secondsRemaining', () => {
	it('counts down within a 30s window', () => {
		expect(secondsRemaining(30, 0)).toBe(30)
		expect(secondsRemaining(30, 1000)).toBe(29)
		expect(secondsRemaining(30, 29000)).toBe(1)
		expect(secondsRemaining(30, 30000)).toBe(30)
	})
})

describe('no-leak contract', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('the generator issues no network request and no storage write', async () => {
		const originalFetch = globalThis.fetch
		const originalLocal = globalThis.localStorage
		const originalSession = globalThis.sessionStorage
		const fetchSpy = vi.fn()
		const xhrSpy = vi.fn()
		globalThis.fetch = fetchSpy
		if (typeof globalThis.XMLHttpRequest === 'function') {
			vi.spyOn(globalThis.XMLHttpRequest.prototype, 'open').mockImplementation(
				xhrSpy,
			)
		}
		const setItem = vi.fn()
		const store = { setItem, getItem: () => null, removeItem: () => {} }
		globalThis.localStorage = store
		globalThis.sessionStorage = store

		try {
			const params = parseOtpauth(
				'otpauth://totp/ACME:alice?secret=' + SHA1_SECRET_BASE32,
			)
			const code = await generateTotp(params, 59 * 1000)

			expect(code).toBeTruthy()
			expect(fetchSpy).not.toHaveBeenCalled()
			expect(xhrSpy).not.toHaveBeenCalled()
			expect(setItem).not.toHaveBeenCalled()
		} finally {
			globalThis.fetch = originalFetch
			globalThis.localStorage = originalLocal
			globalThis.sessionStorage = originalSession
		}
	})
})
