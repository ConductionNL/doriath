/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/PasswordStrengthMeter.vue`.
 *
 * The meter is the only thing that enforces the administrator's
 * master-password floor: `MasterPasswordForm`, `LockScreen` and
 * `CompromiseRecoveryForm` all gate their submit button on the `isValid` flag
 * this component emits, and none of them passes a `minLength` / `minScore`
 * prop. So if the meter does not read the stored policy, the admin panel is a
 * setting with no effect — which is the second half of #192.
 *
 * These tests assert on the EMITTED VERDICT for a specific password, not on
 * "the policy was fetched": a component that fetched the policy and ignored it
 * would pass the weaker check.
 *
 * @spec openspec/specs/org-password-policies/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import zxcvbn from 'zxcvbn'

const fetchPolicy = vi.fn()

vi.mock('../../src/policy/policy.js', () => ({
	fetchPolicy: (...args) => fetchPolicy(...args),
	resetPolicyCache: () => {},
}))

const PasswordStrengthMeter = (
	await import('../../src/components/PasswordStrengthMeter.vue')
).default

/** Drain pending microtasks and the 300ms debounce. */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the meter and return the last `strength-change` payload.
 *
 * @param {string} password The password under test.
 * @param {object} props Extra props.
 * @return {Promise<object>} The last emitted payload.
 */
async function verdictFor(password, props = {}) {
	const wrapper = mount(PasswordStrengthMeter, {
		props: { password, ...props },
	})
	await flush()
	await wrapper.vm.$nextTick()
	const events = wrapper.emitted('strength-change') || []
	return events[events.length - 1]?.[0]
}

describe('PasswordStrengthMeter', () => {
	beforeEach(() => {
		fetchPolicy.mockReset()
		fetchPolicy.mockResolvedValue(null)
	})

	it('falls back to the app floor of 12 characters when no policy is available', async () => {
		// 13 chars, high entropy — passes the 12-char fallback floor.
		const verdict = await verdictFor('correct-horse-battery-staple-42')

		expect(verdict.isValid).toBe(true)
	})

	it('rejects a password below the app fallback length floor', async () => {
		const verdict = await verdictFor('sh0rt-Pw!')

		expect(verdict.isValid).toBe(false)
	})

	it("enforces the administrator's raised length floor", async () => {
		fetchPolicy.mockResolvedValue({
			master_password_min_length: 20,
			master_password_min_score: 3,
		})

		// 16 characters and strong: valid under the 12-char fallback,
		// INVALID under the administrator's 20-character floor.
		const verdict = await verdictFor('correct-horse-42')

		expect(verdict.isValid).toBe(false)
	})

	it("enforces the administrator's raised score floor", async () => {
		// `summerbreeze2026` is 16 characters and zxcvbn scores it exactly 3,
		// so it is VALID under the app fallback floors and INVALID only if the
		// administrator's score floor of 4 is actually being read. A weaker
		// password would fail either way and prove nothing.
		expect(zxcvbn('summerbreeze2026').score).toBe(3)

		expect((await verdictFor('summerbreeze2026')).isValid).toBe(true)

		fetchPolicy.mockResolvedValue({
			master_password_min_length: 12,
			master_password_min_score: 4,
		})

		expect((await verdictFor('summerbreeze2026')).isValid).toBe(false)
	})

	it('adopts the stored floors as the effective floors', async () => {
		fetchPolicy.mockResolvedValue({
			master_password_min_length: 18,
			master_password_min_score: 4,
		})

		const wrapper = mount(PasswordStrengthMeter, {
			props: { password: 'tooshort' },
		})
		await flush()
		await wrapper.vm.$nextTick()

		// The rendered hint interpolates through `t()`, which the test harness
		// stubs without placeholder substitution — so assert on the number the
		// component will enforce, which is the thing that matters.
		expect(wrapper.vm.effectiveMinLength).toBe(18)
		expect(wrapper.vm.effectiveMinScore).toBe(4)
	})

	it('lets an explicit prop win over the stored policy', async () => {
		fetchPolicy.mockResolvedValue({
			master_password_min_length: 30,
			master_password_min_score: 4,
		})

		const verdict = await verdictFor('correct-horse-battery-staple-42', {
			minLength: 12,
			minScore: 3,
		})

		expect(verdict.isValid).toBe(true)
	})

	it('never becomes permissive when the policy request fails', async () => {
		fetchPolicy.mockRejectedValue(new Error('network'))

		const verdict = await verdictFor('sh0rt!')

		expect(verdict.isValid).toBe(false)
	})
})
