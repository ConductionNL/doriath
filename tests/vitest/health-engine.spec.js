/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the client-side health engine (src/health/engine.js). Runs in
 * the node env (WebCrypto for the SHA-256 reuse map). Locks: strength scoring
 * (password-bearing only), reuse buckets (both copies flagged; fix clears
 * both), staleness against thresholds, the weighted vault-score formula incl.
 * the possibly-compromised input, and the no-plaintext-leak shape of findings.
 */

import { describe, expect, it } from 'vitest'
import {
	ageInDays,
	analyse,
	staleCutoffDays,
	vaultScore,
	WEAK_SCORE_THRESHOLD,
} from '../../src/health/engine.js'

const DAY = 86400000

describe('engine: strength scoring', () => {
	it('flags a weak password and not a strong one', async () => {
		const rows = [
			{ id: 'a', name: 'Weak', value: 'password' },
			{ id: 'b', name: 'Strong', value: 'Tr0ub4dor&3-XKCD-correct' },
		]
		const { findings } = await analyse(rows, { stalenessThreshold: 'never' })
		const weak = findings.find((f) => f.id === 'a')
		const strong = findings.find((f) => f.id === 'b')
		expect(weak.flags).toContain('weak')
		expect(weak.score).toBeLessThanOrEqual(WEAK_SCORE_THRESHOLD)
		expect(strong.flags).not.toContain('weak')
	})

	it('does NOT strength-score key material (PEM)', async () => {
		const rows = [
			{
				id: 'k',
				name: 'Key',
				value: '-----BEGIN PRIVATE KEY-----\n' + 'a'.repeat(80),
			},
		]
		const { findings, summary } = await analyse(rows, {
			stalenessThreshold: 'never',
		})
		const f = findings.find((x) => x.id === 'k')
		// either excluded entirely (no flags + null score) -> no weak finding
		expect(summary.weakCount).toBe(0)
		if (f) {
			expect(f.score).toBeNull()
		}
	})
})

describe('engine: reuse detection', () => {
	it('flags both secrets sharing a value with the share count', async () => {
		const rows = [
			{ id: 'a', name: 'A', value: 'Summer2024!' },
			{ id: 'b', name: 'B', value: 'Summer2024!' },
			{ id: 'c', name: 'C', value: 'Unique-One-9' },
		]
		const { findings, summary } = await analyse(rows, {
			stalenessThreshold: 'never',
		})
		expect(summary.reusedCount).toBe(2)
		expect(findings.find((f) => f.id === 'a').flags).toContain('reused')
		expect(findings.find((f) => f.id === 'b').flags).toContain('reused')
		expect(findings.find((f) => f.id === 'a').shareCount).toBe(2)
		expect(
			(findings.find((f) => f.id === 'c') || { flags: [] }).flags,
		).not.toContain('reused')
	})

	it('clears the reuse flag once one of the pair changes', async () => {
		const before = await analyse(
			[
				{ id: 'a', name: 'A', value: 'same-value-1' },
				{ id: 'b', name: 'B', value: 'same-value-1' },
			],
			{ stalenessThreshold: 'never' },
		)
		expect(before.summary.reusedCount).toBe(2)

		const after = await analyse(
			[
				{ id: 'a', name: 'A', value: 'same-value-1' },
				{ id: 'b', name: 'B', value: 'changed-value-2' },
			],
			{ stalenessThreshold: 'never' },
		)
		expect(after.summary.reusedCount).toBe(0)
	})

	it('detects reuse of non-password key material too', async () => {
		const token = 'A1b2C3d4'.repeat(8)
		const rows = [
			{ id: 'a', name: 'API one', value: token },
			{ id: 'b', name: 'API two', value: token },
		]
		const { summary } = await analyse(rows, { stalenessThreshold: 'never' })
		expect(summary.reusedCount).toBe(2)
	})
})

describe('engine: staleness', () => {
	const now = Date.UTC(2026, 0, 1)

	it('flags a key older than the threshold', async () => {
		const old = new Date(now - 400 * DAY).toISOString()
		const rows = [
			{ id: 'a', name: 'Old', value: 'Whatever-Strong-12', keyUpdatedAt: old },
		]
		const { summary } = await analyse(rows, { stalenessThreshold: '365', now })
		expect(summary.staleCount).toBe(1)
	})

	it('does NOT flag when the threshold is never', async () => {
		const old = new Date(now - 4000 * DAY).toISOString()
		const rows = [{ id: 'a', name: 'Old', value: 'x', keyUpdatedAt: old }]
		const { summary } = await analyse(rows, { stalenessThreshold: 'never', now })
		expect(summary.staleCount).toBe(0)
	})

	it('ageInDays + staleCutoffDays helpers', () => {
		expect(ageInDays(new Date(now - 10 * DAY).toISOString(), now)).toBe(10)
		expect(ageInDays(null, now)).toBeNull()
		expect(staleCutoffDays('90')).toBe(90)
		expect(staleCutoffDays('never')).toBeNull()
	})
})

describe('engine: possibly-compromised + weighted score', () => {
	it('counts possibly-compromised secrets', async () => {
		const rows = [
			{
				id: 'a',
				name: 'C',
				value: 'Strong-Pass-99x',
				possiblyCompromisedAt: '2026-01-01T00:00:00Z',
			},
		]
		const { summary } = await analyse(rows, { stalenessThreshold: 'never' })
		expect(summary.compromisedCount).toBe(1)
	})

	it('vaultScore is 100 for an empty / clean vault and drops with findings', () => {
		expect(vaultScore({ analysedCount: 0 })).toBe(100)
		expect(
			vaultScore({
				analysedCount: 10,
				weakCount: 0,
				reusedCount: 0,
				staleCount: 0,
				breachedCount: 0,
				compromisedCount: 0,
			}),
		).toBe(100)
		// 1 breached out of 10 -> 100 * (1 - 1.0/10) = 90
		expect(vaultScore({ analysedCount: 10, breachedCount: 1 })).toBe(90)
		// clamps at 0
		expect(vaultScore({ analysedCount: 1, breachedCount: 5 })).toBe(0)
	})
})

describe('engine: no plaintext in findings', () => {
	it('findings never carry the decrypted value', async () => {
		const rows = [{ id: 'a', name: 'A', value: 'super-secret-plaintext' }]
		const { findings } = await analyse(rows, { stalenessThreshold: 'never' })
		const serialised = JSON.stringify(findings)
		expect(serialised).not.toContain('super-secret-plaintext')
	})
})
