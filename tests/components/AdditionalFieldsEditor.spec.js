/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/AdditionalFieldsEditor.vue`.
 *
 * The editor is shared by the secret-create and secret-edit dialogs, and its
 * validation is shared further still with the request dialog. So the behaviour
 * pinned here is what all three depend on: a reserved name is refused WITH A
 * REASON, duplicates and blanks are refused, and add / rename / re-value / remove
 * emit the list the caller will turn into one encrypted blob.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AdditionalFieldsEditor from '../../src/components/AdditionalFieldsEditor.vue'

const stubs = {
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" @keyup.enter="$emit(\'keyup\', $event)" />',
	},
	NcButton: {
		props: ['disabled', 'ariaLabel', 'variant'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}

function mountEditor(members = []) {
	return mount(AdditionalFieldsEditor, {
		propsData: { members },
		global: { stubs },
	})
}

describe('AdditionalFieldsEditor', () => {
	it('says there are none rather than showing an empty list', () => {
		const wrapper = mountEditor([])

		expect(
			wrapper.find('[data-testid="additional-fields-empty"]').exists(),
		).toBe(true)
	})

	it('renders one row per member, with name and value', () => {
		const wrapper = mountEditor([
			{ name: 'client-id', value: 'abc' },
			{ name: 'tenant', value: 'acme' },
		])

		expect(wrapper.find('[data-testid="additional-field-row-0"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="additional-field-row-1"]').exists()).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="additional-field-name-0"]').element.value,
		).toBe('client-id')
		expect(
			wrapper.find('[data-testid="additional-field-value-1"]').element.value,
		).toBe('acme')
	})

	it('adds a member and emits the new list', async () => {
		const wrapper = mountEditor([])

		await wrapper
			.find('[data-testid="additional-field-new-name"]')
			.setValue('client-id')
		await wrapper.find('[data-testid="additional-field-add"]').trigger('click')

		expect(wrapper.emitted('update:members')[0][0]).toEqual([
			{ name: 'client-id', value: '' },
		])
		// The input clears, so the next name does not have to be deleted first.
		expect(wrapper.vm.newName).toBe('')
	})

	it('refuses a reserved name WITH a reason, and emits nothing', async () => {
		// `key` addresses the Secret's own column. Accepting it would look like a
		// second field with that label while the value is misrouted or shadowed.
		const wrapper = mountEditor([])

		await wrapper
			.find('[data-testid="additional-field-new-name"]')
			.setValue('key')
		await wrapper.find('[data-testid="additional-field-add"]').trigger('click')

		const error = wrapper.find('[data-testid="additional-field-error"]')
		expect(error.exists()).toBe(true)
		expect(error.text().length).toBeGreaterThan(0)
		expect(wrapper.emitted('update:members')).toBeUndefined()
	})

	it('refuses a duplicate and a blank, and emits nothing', async () => {
		const wrapper = mountEditor([{ name: 'tenant', value: 'acme' }])

		await wrapper
			.find('[data-testid="additional-field-new-name"]')
			.setValue('tenant')
		await wrapper.find('[data-testid="additional-field-add"]').trigger('click')
		expect(wrapper.find('[data-testid="additional-field-error"]').exists()).toBe(
			true,
		)

		await wrapper
			.find('[data-testid="additional-field-new-name"]')
			.setValue('   ')
		await wrapper.find('[data-testid="additional-field-add"]').trigger('click')
		expect(wrapper.find('[data-testid="additional-field-error"]').exists()).toBe(
			true,
		)

		expect(wrapper.emitted('update:members')).toBeUndefined()
	})

	it('renames a member in place rather than replacing it', async () => {
		// Position matters: the rows are keyed by index precisely so a rename stays
		// one edit instead of becoming a remove plus an add, which would lose focus
		// and reorder the list under the user.
		const wrapper = mountEditor([
			{ name: 'old', value: 'keepme' },
			{ name: 'other', value: '2' },
		])

		await wrapper.find('[data-testid="additional-field-name-0"]').setValue('new')

		expect(wrapper.emitted('update:members')[0][0]).toEqual([
			{ name: 'new', value: 'keepme' },
			{ name: 'other', value: '2' },
		])
	})

	it('flags a rename onto a reserved or duplicate name', async () => {
		const wrapper = mountEditor([
			{ name: 'a', value: '1' },
			{ name: 'b', value: '2' },
		])

		await wrapper.find('[data-testid="additional-field-name-0"]').setValue('url')
		expect(wrapper.find('[data-testid="additional-field-error"]').exists()).toBe(
			true,
		)

		await wrapper.find('[data-testid="additional-field-name-0"]').setValue('b')
		expect(wrapper.find('[data-testid="additional-field-error"]').exists()).toBe(
			true,
		)
	})

	it('does not flag a member for colliding with ITSELF', async () => {
		// Renaming `a` to `a` (or editing around it) must not report a duplicate:
		// the row is compared against the OTHERS, not the whole list.
		const wrapper = mountEditor([{ name: 'a', value: '1' }])

		await wrapper.find('[data-testid="additional-field-name-0"]').setValue('a')

		expect(wrapper.find('[data-testid="additional-field-error"]').exists()).toBe(
			false,
		)
	})

	it('changes a value without touching the name', async () => {
		const wrapper = mountEditor([{ name: 'client-id', value: 'old' }])

		await wrapper
			.find('[data-testid="additional-field-value-0"]')
			.setValue('new')

		expect(wrapper.emitted('update:members')[0][0]).toEqual([
			{ name: 'client-id', value: 'new' },
		])
	})

	it('removes a member and can empty the list entirely', async () => {
		const wrapper = mountEditor([{ name: 'only', value: '1' }])

		await wrapper
			.find('[data-testid="additional-field-remove-0"]')
			.trigger('click')

		// An EMPTY list, which the caller turns into an empty blob rather than null.
		expect(wrapper.emitted('update:members')[0][0]).toEqual([])
	})

	it('disables every control while editing is blocked', () => {
		const wrapper = mountEditor([{ name: 'a', value: '1' }])
		expect(
			wrapper.find('[data-testid="additional-field-name-0"]').element.disabled,
		).toBe(false)

		const locked = mount(AdditionalFieldsEditor, {
			propsData: { members: [{ name: 'a', value: '1' }], disabled: true },
			global: { stubs },
		})

		expect(
			locked.find('[data-testid="additional-field-name-0"]').element.disabled,
		).toBe(true)
		expect(
			locked.find('[data-testid="additional-field-new-name"]').element
				.disabled,
		).toBe(true)
	})
})
