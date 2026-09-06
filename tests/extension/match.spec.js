/**
 * @spec openspec/changes/browser-extension-autofill/specs/browser-extension-autofill/spec.md
 *
 * URL/host matching over the UNENCRYPTED index fields (browser-extension-
 * autofill §4.1, task 6.2). Matching never touches ciphertext.
 */
import { describe, expect, it } from 'vitest'
import {
	hostOf,
	matchScore,
	matchSecrets,
	registrableDomain,
} from '../../browser-extension/src/lib/match.js'

describe('extension host matching', () => {
	it('extracts hosts from URLs and bare hosts', () => {
		expect(hostOf('https://login.example.gov/path')).toBe('login.example.gov')
		expect(hostOf('example.com')).toBe('example.com')
		expect(hostOf('')).toBe('')
		expect(hostOf('not a url')).toBe('')
	})

	it('computes registrable domain incl. multi-label suffixes', () => {
		expect(registrableDomain('login.example.com')).toBe('example.com')
		expect(registrableDomain('a.b.example.co.uk')).toBe('example.co.uk')
		expect(registrableDomain('portal.gemeente.gov.nl')).toBe('gemeente.gov.nl')
		expect(registrableDomain('localhost')).toBe('localhost')
	})

	it('scores exact host highest, same-site next, name fallback lowest', () => {
		expect(matchScore({ url: 'https://example.com' }, 'example.com')).toBe(100)
		expect(matchScore({ url: 'https://login.example.com' }, 'example.com')).toBe(
			80,
		)
		expect(
			matchScore({ name: 'Login for example.com', url: '' }, 'example.com'),
		).toBe(40)
		expect(matchScore({ name: 'Example mail', url: '' }, 'example.com')).toBe(20)
		expect(
			matchScore({ name: 'Nope', url: 'https://other.org' }, 'example.com'),
		).toBe(0)
	})

	it('ranks candidates best-first and drops non-matches', () => {
		const secrets = [
			{ id: '1', name: 'Other', url: 'https://other.org' },
			{ id: '2', name: 'Exact', url: 'https://example.com' },
			{ id: '3', name: 'Sub', url: 'https://app.example.com' },
		]
		const ranked = matchSecrets(secrets, 'example.com')
		expect(ranked.map((r) => r.id)).toEqual(['2', '3'])
		expect(ranked.every((r) => r._score > 0)).toBe(true)
	})
})
