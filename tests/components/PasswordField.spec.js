/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/PasswordField.vue`.
 *
 * PasswordField is the masked key/password row used on SecretDetail and
 * inside the additional-fields editor. It MUST:
 *  - default to MASKED (the resolver MUST NOT run on mount; that would
 *    defeat the on-demand decryption optimisation)
 *  - decrypt lazily on the first show OR the first copy
 *  - cache the plaintext after the first reveal so toggling hide/show
 *    again does not re-run the resolver
 *  - expose the plaintext to CopyButton via the `resolve` prop, NOT via
 *    a string value (so the same lazy-decrypt contract holds)
 *
 * @spec openspec/changes/implement-secrets/tasks.md#7.7
 * @spec openspec/changes/implement-secrets/tasks.md#13.3
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PasswordField from '../../src/components/PasswordField.vue'

describe('PasswordField', () => {
	beforeEach(() => {
		// Same clipboard stub as the CopyButton spec uses.
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: vi.fn().mockResolvedValue() },
			configurable: true,
		})
		document.execCommand = vi.fn().mockReturnValue(true)
	})

	it('does NOT call the resolver on mount (default masked + lazy decrypt)', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const wrapper = mount(PasswordField, {
			propsData: { resolve },
		})

		await wrapper.vm.$nextTick()

		expect(resolve).not.toHaveBeenCalled()
		expect(wrapper.vm.revealed).toBe(false)
		expect(wrapper.vm.plain).toBeNull()
		// And the masked placeholder is what the input field receives.
		expect(wrapper.vm.displayValue).toBe('••••••••••')
	})

	it('toggle(): decrypts on the first show and reveals the plaintext', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const wrapper = mount(PasswordField, {
			propsData: { resolve },
		})

		await wrapper.vm.toggle()

		expect(resolve).toHaveBeenCalledOnce()
		expect(wrapper.vm.plain).toBe('hunter2')
		expect(wrapper.vm.revealed).toBe(true)
		expect(wrapper.vm.displayValue).toBe('hunter2')
	})

	it('toggle(): caches the plaintext — hiding then re-revealing does NOT re-resolve', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const wrapper = mount(PasswordField, {
			propsData: { resolve },
		})

		await wrapper.vm.toggle() // show
		await wrapper.vm.toggle() // hide
		await wrapper.vm.toggle() // show again

		expect(resolve).toHaveBeenCalledOnce()
		expect(wrapper.vm.revealed).toBe(true)
	})

	it('hides again after a second toggle', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const wrapper = mount(PasswordField, {
			propsData: { resolve },
		})

		await wrapper.vm.toggle() // show
		expect(wrapper.vm.revealed).toBe(true)

		await wrapper.vm.toggle() // hide
		expect(wrapper.vm.revealed).toBe(false)
		expect(wrapper.vm.displayValue).toBe('••••••••••')
	})

	it('resolvePlain(): decrypts on first copy and caches for subsequent calls', async () => {
		const resolve = vi.fn().mockResolvedValue('decrypted-key')
		const wrapper = mount(PasswordField, {
			propsData: { resolve },
		})

		const first = await wrapper.vm.resolvePlain()
		const second = await wrapper.vm.resolvePlain()

		expect(first).toBe('decrypted-key')
		expect(second).toBe('decrypted-key')
		// The resolver ran exactly once across both reads.
		expect(resolve).toHaveBeenCalledOnce()
	})

	it('renders the field with the custom `label` prop', async () => {
		const resolve = vi.fn().mockResolvedValue('x')
		const wrapper = mount(PasswordField, {
			propsData: { resolve, label: 'My API token' },
		})

		await wrapper.vm.$nextTick()

		// The NcInputField stub forwards `label` as a prop on the rendered
		// element so we can probe the rendered tree.
		const html = wrapper.html()
		expect(html).toContain('NcInputField')
		// And the component data carries the custom label through.
		expect(wrapper.vm.label).toBe('My API token')
	})
})
