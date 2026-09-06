/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for client-side org-policy evaluation (org-password-policies
 * §6.3): a weak manual value is blocked, an exempt type skips all
 * checks, the HIBP block fires only on a breached verdict, and an
 * unavailable proxy never blocks a save.
 *
 * @spec openspec/specs/org-password-policies/spec.md#requirement-client-side-save-enforcement
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/health/hibp.js', () => ({
	checkValue: vi.fn(async (value) => {
		if (value === 'breached-value') {
			return { status: 'breached', count: 42 }
		}
		if (value === 'proxy-down') {
			return { status: 'unavailable', count: 0 }
		}
		return { status: 'clean', count: 0 }
	}),
}))

import { checkValue } from '../../src/health/hibp.js'
import {
	evaluateHibp,
	evaluateScore,
	isExemptType,
} from '../../src/policy/policy.js'

const POLICY = {
	policy_enabled: true,
	min_zxcvbn_score: 3,
	block_on_hibp_hit: true,
	policy_exempt_types: ['note', 'ssh_key'],
}

describe('org policy: score gate', () => {
	it('blocks a weak manual value with a reason', () => {
		const verdict = evaluateScore(POLICY, 'login', 'password1')
		expect(verdict.compliant).toBe(false)
		expect(verdict.reason).toContain('below the org minimum')
	})

	it('passes a strong value', () => {
		const verdict = evaluateScore(
			POLICY,
			'login',
			'x7$Kq99!theRealHorseBatteryStaple-2026',
		)
		expect(verdict.compliant).toBe(true)
	})

	it('skips checks entirely for an exempt type', () => {
		expect(isExemptType(POLICY, 'note')).toBe(true)
		const verdict = evaluateScore(POLICY, 'note', 'weak')
		expect(verdict.compliant).toBe(true)
	})

	it('is a no-op when the policy gate is off or missing', () => {
		expect(
			evaluateScore({ ...POLICY, policy_enabled: false }, 'login', 'weak')
				.compliant,
		).toBe(true)
		expect(evaluateScore(null, 'login', 'weak').compliant).toBe(true)
	})
})

describe('org policy: HIBP gate', () => {
	it('blocks a breached value with a reason', async () => {
		// The node-env t() stub returns the raw template — assert the
		// phrase, not the interpolation.
		const reason = await evaluateHibp(POLICY, 'login', 'breached-value')
		expect(reason).toContain('known breaches')
	})

	it('passes a clean value and never checks an exempt type', async () => {
		expect(await evaluateHibp(POLICY, 'login', 'clean-value')).toBeNull()
		checkValue.mockClear()
		expect(await evaluateHibp(POLICY, 'note', 'breached-value')).toBeNull()
		expect(checkValue).not.toHaveBeenCalled()
	})

	it('never blocks when the proxy is unavailable', async () => {
		expect(await evaluateHibp(POLICY, 'login', 'proxy-down')).toBeNull()
	})

	it('never runs when the block is off', async () => {
		checkValue.mockClear()
		expect(
			await evaluateHibp(
				{ ...POLICY, block_on_hibp_hit: false },
				'login',
				'breached-value',
			),
		).toBeNull()
		expect(checkValue).not.toHaveBeenCalled()
	})
})
