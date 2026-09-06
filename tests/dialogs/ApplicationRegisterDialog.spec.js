/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/ApplicationRegisterDialog.vue`.
 *
 * The first cut of this dialog was a bare <section role="dialog"> in document
 * flow: it mounted below the fold with no overlay, so "Register application"
 * looked like a dead button. What these tests pin:
 *
 *  - The dialog renders through NcDialog, so it is an overlay and can never
 *    fall back into document flow again.
 *  - Nothing is sent on mount; the store is only touched on submit, with the
 *    trimmed name and the chosen type.
 *  - A REFUSED registration keeps the dialog open and reports why, so a 500
 *    does not read like a registration that succeeded.
 *
 * The `@nextcloud/vue` design primitives resolve to the flat stub dict in
 * `tests/vitest/stubs/nextcloud-vue.js` (vitest alias): NcDialog renders its
 * slots in place (no teleport) with a `data-open` attribute, and field stubs
 * forward `update:modelValue`.
 *
 * @spec openspec/specs/application-mgmt/spec.md#requirement-register-application
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApplicationRegisterDialog from '../../src/dialogs/ApplicationRegisterDialog.vue'
import { useApplicationStore } from '../../src/store/modules/application.js'

/**
 * Mount the dialog open, as the view renders it.
 *
 * @param {object} propsData Extra props to merge over the defaults.
 * @return {object} The wrapper.
 */
function mountDialog(propsData = {}) {
	return mount(ApplicationRegisterDialog, {
		propsData: { open: true, ...propsData },
	})
}

/**
 * Type into one of the stubbed field components by test id.
 *
 * @param {object} wrapper The mounted wrapper.
 * @param {string} testid The field's data-testid.
 * @param {string} value The new model value.
 * @return {Promise<void>}
 */
async function setField(wrapper, testid, value) {
	const field = wrapper
		.findAllComponents({ name: 'NcTextField' })
		.concat(wrapper.findAllComponents({ name: 'NcTextArea' }))
		.find((c) => c.attributes('data-testid') === testid)
	field.vm.$emit('update:modelValue', value)
	await wrapper.vm.$nextTick()
}

describe('ApplicationRegisterDialog', () => {
	let applicationStore

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		applicationStore = useApplicationStore()
	})

	it('renders as an NcDialog overlay that follows the open prop', async () => {
		const wrapper = mountDialog()
		const dialog = wrapper.findComponent({ name: 'NcDialog' })
		expect(dialog.exists()).toBe(true)
		expect(dialog.attributes('data-open')).toBe('true')

		await wrapper.setProps({ open: false })
		expect(dialog.attributes('data-open')).toBe('false')
	})

	it('sends nothing until submit, then posts the trimmed form', async () => {
		const register = vi
			.spyOn(applicationStore, 'registerApplication')
			.mockResolvedValue({ id: 'app-1' })

		const wrapper = mountDialog()
		expect(register).not.toHaveBeenCalled()

		await setField(wrapper, 'application-register-name', '  CI runner  ')
		await wrapper
			.find('[data-testid="application-register-submit"]')
			.trigger('click')

		expect(register).toHaveBeenCalledWith({
			name: 'CI runner',
			description: '',
			type: 'internal',
			csr: null,
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('registered')).toEqual([[{ id: 'app-1' }]])
	})

	it('carries the picked type radio into the payload', async () => {
		const register = vi
			.spyOn(applicationStore, 'registerApplication')
			.mockResolvedValue({ id: 'app-1' })

		const wrapper = mountDialog()
		await setField(wrapper, 'application-register-name', 'Offsite sync')
		const external = wrapper
			.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
			.find(
				(c) =>
					c.attributes('data-testid')
					=== 'application-register-type-external',
			)
		external.vm.$emit('update:modelValue', 'external')
		await wrapper.vm.$nextTick()

		await wrapper
			.find('[data-testid="application-register-submit"]')
			.trigger('click')

		expect(register).toHaveBeenCalledWith(
			expect.objectContaining({ type: 'external' }),
		)
	})

	// A refused registration that closed the dialog would read exactly like
	// one that worked — the form would be gone and no row would appear.
	it('stays open with the server reason when the registration is refused', async () => {
		vi.spyOn(applicationStore, 'registerApplication').mockRejectedValue({
			response: { status: 500, data: { message: 'CSR unparsable' } },
		})

		const wrapper = mountDialog()
		await setField(wrapper, 'application-register-name', 'CI runner')
		await wrapper
			.find('[data-testid="application-register-submit"]')
			.trigger('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('registered')).toBeFalsy()
		expect(wrapper.emitted('close')).toBeFalsy()
		expect(
			wrapper.find('[data-testid="application-register-error"]').text(),
		).toContain('CSR unparsable')
	})

	it('refuses an all-whitespace name without touching the store', async () => {
		const register = vi.spyOn(applicationStore, 'registerApplication')

		const wrapper = mountDialog()
		await setField(wrapper, 'application-register-name', '   ')
		await wrapper.find('form').trigger('submit')
		await wrapper.vm.$nextTick()

		expect(register).not.toHaveBeenCalled()
		expect(
			wrapper.find('[data-testid="application-register-error"]').text(),
		).toContain('Name is required')
	})

	it('emits close when the dialog chrome dismisses it', async () => {
		const wrapper = mountDialog()
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	// Review follow-ups (#601):

	it('trims description and CSR like it already trims the name', async () => {
		const register = vi
			.spyOn(applicationStore, 'registerApplication')
			.mockResolvedValue({ id: 'app-1' })

		const wrapper = mountDialog()
		await setField(wrapper, 'application-register-name', 'CI runner')
		await setField(
			wrapper,
			'application-register-description',
			'  pasted with a trailing newline\n',
		)
		await setField(
			wrapper,
			'application-register-csr',
			'\n-----BEGIN CERTIFICATE REQUEST-----\nabc\n-----END CERTIFICATE REQUEST-----\n',
		)
		await wrapper
			.find('[data-testid="application-register-submit"]')
			.trigger('click')

		expect(register).toHaveBeenCalledWith({
			name: 'CI runner',
			description: 'pasted with a trailing newline',
			type: 'internal',
			csr: '-----BEGIN CERTIFICATE REQUEST-----\nabc\n-----END CERTIFICATE REQUEST-----',
		})
	})

	// A cnOpenModal host that only wires @registered must not be left with
	// a stuck dialog: success closes the dialog itself.
	it('emits close as well as registered on success', async () => {
		vi.spyOn(applicationStore, 'registerApplication').mockResolvedValue({
			id: 'app-1',
		})

		const wrapper = mountDialog()
		await setField(wrapper, 'application-register-name', 'CI runner')
		await wrapper
			.find('[data-testid="application-register-submit"]')
			.trigger('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('registered')).toBeTruthy()
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	// The form's @submit and the actions-slot button share onSubmit; the
	// busy gate makes a double fire structurally harmless.
	it('registers once when the handler fires twice for one gesture', async () => {
		let resolveRegister
		const register = vi
			.spyOn(applicationStore, 'registerApplication')
			.mockImplementation(
				() =>
					new Promise((resolve) => {
						resolveRegister = resolve
					}),
			)

		const wrapper = mountDialog()
		await setField(wrapper, 'application-register-name', 'CI runner')

		const first = wrapper.vm.onSubmit()
		const second = wrapper.vm.onSubmit()
		resolveRegister({ id: 'app-1' })
		await Promise.all([first, second])

		expect(register).toHaveBeenCalledTimes(1)
	})
})
