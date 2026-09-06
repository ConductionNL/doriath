/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Component test for `src/components/TotpDisplay.vue`.
 *
 * TotpDisplay renders the live RFC 6238 one-time code for a `totp` secret,
 * computed entirely in the browser from the decrypted seed. It MUST:
 *  - render a code + countdown while the vault is unlocked and the seed valid
 *  - show an explicit invalid state (and NEVER a code) for an unparseable seed
 *  - discard all TOTP state (code, timer) on vault lock and on destroy
 *  - never write the seed / code to localStorage or sessionStorage
 *
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import TotpDisplay from '../../src/components/TotpDisplay.vue'
import { useSessionStore } from '../../src/store/modules/session.js'

const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Wait until the async WebCrypto code-generation settles (or a timeout) so
 * assertions run after the first tick() has produced a code.
 *
 * @param {object} vm The component instance.
 * @return {Promise<void>}
 */
async function waitForCode(vm) {
	for (let i = 0; i < 50; i++) {
		if (vm.code !== '' || vm.invalid) {
			await vm.$nextTick()
			return
		}
		await new Promise((resolve) => setTimeout(resolve, 5))
	}
	await vm.$nextTick()
}

describe('TotpDisplay', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders a one-time code + countdown for a valid seed while unlocked', async () => {
		const session = useSessionStore()
		session.cryptoKey = { fake: true } // unlocked

		const wrapper = mount(TotpDisplay, { propsData: { seed: SECRET } })
		await waitForCode(wrapper.vm)

		const code = wrapper.find('[data-testid="totp-code"]')
		expect(code.exists()).toBe(true)
		expect(code.text().replace(/\s/g, '')).toMatch(/^\d{6}$/)
		expect(wrapper.find('[data-testid="totp-countdown"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="totp-invalid"]').exists()).toBe(false)

		wrapper.unmount()
	})

	it('shows the invalid state and never a code for an unparseable seed', async () => {
		const session = useSessionStore()
		session.cryptoKey = { fake: true }

		const wrapper = mount(TotpDisplay, {
			propsData: { seed: 'not a valid seed !!!' },
		})
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="totp-invalid"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="totp-code"]').exists()).toBe(false)

		wrapper.unmount()
	})

	it('renders nothing (no code) when the vault is locked', async () => {
		const session = useSessionStore()
		session.cryptoKey = null // locked

		const wrapper = mount(TotpDisplay, { propsData: { seed: SECRET } })
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="totp-code"]').exists()).toBe(false)

		wrapper.unmount()
	})

	it('discards all TOTP state when the vault locks', async () => {
		const session = useSessionStore()
		session.cryptoKey = { fake: true }

		const wrapper = mount(TotpDisplay, { propsData: { seed: SECRET } })
		await waitForCode(wrapper.vm)
		expect(wrapper.vm.code).not.toBe('')

		session.lock()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.code).toBe('')
		expect(wrapper.vm.params).toBeNull()
		expect(wrapper.vm.timer).toBeNull()

		wrapper.unmount()
	})

	it('does not write the seed or code to browser storage', async () => {
		const session = useSessionStore()
		session.cryptoKey = { fake: true }
		const setItem = vi.spyOn(Storage.prototype, 'setItem')

		const wrapper = mount(TotpDisplay, { propsData: { seed: SECRET } })
		await flush()
		await wrapper.vm.$nextTick()

		expect(setItem).not.toHaveBeenCalled()

		wrapper.unmount()
	})

	it('clears the recompute timer on destroy', async () => {
		const session = useSessionStore()
		session.cryptoKey = { fake: true }

		const wrapper = mount(TotpDisplay, { propsData: { seed: SECRET } })
		await waitForCode(wrapper.vm)
		expect(wrapper.vm.timer).not.toBeNull()

		wrapper.unmount()
		expect(wrapper.vm.timer).toBeNull()
	})
})
