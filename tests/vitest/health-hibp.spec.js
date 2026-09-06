/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the HIBP k-anonymity client (src/health/hibp.js). Runs in the
 * node env (WebCrypto SHA-1). Locks: only the 5-char prefix is requested; the
 * suffix match is local with an occurrence count; soft-fail to `unavailable`
 * on a fetch error. The full hash and value never leave the browser.
 */

import { describe, expect, it } from 'vitest'
import { checkValue, matchSuffix, sha1Hex } from '../../src/health/hibp.js'

describe('hibp: sha1Hex', () => {
	it('computes the known SHA-1 of "password"', async () => {
		// SHA-1("password") = 5BAA61E4C9B93F3F0682250B6CF8331B7EE68FD8
		const hash = await sha1Hex('password')
		expect(hash).toBe('5BAA61E4C9B93F3F0682250B6CF8331B7EE68FD8')
	})
})

describe('hibp: matchSuffix', () => {
	it('finds the count for a matching suffix', () => {
		const body = 'AA61E4C9B93F3F0682250B6CF8331B7EE68FD8:42\r\nFFFF:1'
		expect(matchSuffix(body, 'AA61E4C9B93F3F0682250B6CF8331B7EE68FD8')).toBe(42)
	})

	it('returns 0 when the suffix is absent', () => {
		expect(matchSuffix('FFFF:1\r\nEEEE:2', 'AAAA')).toBe(0)
	})

	it('returns 0 for an empty body', () => {
		expect(matchSuffix('', 'AAAA')).toBe(0)
	})
})

describe('hibp: checkValue', () => {
	it('sends ONLY the 5-char prefix to the range fetcher', async () => {
		let seenPrefix = null
		const fetchRange = async (prefix) => {
			seenPrefix = prefix
			// SHA-1("password") = 5BAA6 1E4C9B93F3F0682250B6CF8331B7EE68FD8 —
			// the 35-char suffix after the 5-char prefix is what we match locally.
			return '1E4C9B93F3F0682250B6CF8331B7EE68FD8:99999'
		}
		const result = await checkValue('password', fetchRange)
		expect(seenPrefix).toBe('5BAA6')
		expect(seenPrefix.length).toBe(5)
		expect(result.status).toBe('breached')
		expect(result.count).toBe(99999)
	})

	it('reports clean when the suffix is not in the range', async () => {
		const fetchRange = async () => 'DEADBEEF:1'
		const result = await checkValue('password', fetchRange)
		expect(result.status).toBe('clean')
		expect(result.count).toBe(0)
	})

	it('soft-fails to unavailable when the fetcher throws', async () => {
		const fetchRange = async () => {
			throw new Error('network down')
		}
		const result = await checkValue('password', fetchRange)
		expect(result.status).toBe('unavailable')
	})
})
