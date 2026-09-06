/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/KeyGeneratorModal.vue` — the server-driven
 * key-generation modal that POSTs to `/api/v1/generate-key` and emits the
 * resulting plaintext to the parent on "Use".
 *
 * What these lock down:
 *  - clicking Generate POSTs the simple-config payload (length + special +
 *    excludedCharacters) and renders the generated key in the preview field;
 *  - the regex override path POSTs only `{ regex }` and disables the basic
 *    fieldset (validated indirectly via the payload assertion);
 *  - server errors surface in the NcNoteCard;
 *  - clicking Use emits `generated` with the previewed key and closes the
 *    dialog (`update:open` false).
 *
 * Running under jsdom — mounts the SFC with shallow stubs for `@nextcloud/vue`
 * components so the design-system tree isn't pulled into the bundle.
 *
 * @spec openspec/changes/implement-key-generator/tasks.md#8.1
 * @spec openspec/changes/implement-key-generator/tasks.md#8.2
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KeyGeneratorModal from '../../src/dialogs/KeyGeneratorModal.vue'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template:
			'<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['type', 'disabled'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		props: ['checked', 'type'],
		template: '<label class="nc-checkbox-stub"><slot /></label>',
	},
	NcInputField: {
		props: [
			'value',
			'label',
			'min',
			'max',
			'type',
			'helperText',
			'readOnly',
			'showTrailingButton',
			'trailingButtonLabel',
		],
		template: '<div class="nc-input-stub" :data-label="label">{{ value }}</div>',
	},
	NcLoadingIcon: { template: '<span class="nc-loading-stub" />' },
	NcNoteCard: {
		props: ['type'],
		template: '<div class="nc-note-card-stub" :data-type="type"><slot /></div>',
	},
	ContentCopy: { template: '<i class="icon-copy" />' },
	Dice5: { template: '<i class="icon-dice5" />' },
}

describe('KeyGeneratorModal', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('generate(): POSTs the basic payload and renders the generated key', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { generatedKey: 'Aa1!Bb2@Cc3#Dd4$' },
		})

		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		await wrapper.vm.generate()

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/apps/keepiq/api/v1/generate-key'),
			{
				length: 16,
				includeSpecialCharacters: true,
				excludedCharacters: '',
			},
		)
		expect(wrapper.vm.generatedKey).toBe('Aa1!Bb2@Cc3#Dd4$')
		expect(wrapper.vm.error).toBeNull()
	})

	it('generate(): when regex is set, POSTs only { regex } (basic options suppressed)', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { generatedKey: 'ABC-123' },
		})

		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.regex = '[A-Z]{3}-\\d{3}'
		await wrapper.vm.generate()

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/apps/keepiq/api/v1/generate-key'),
			{ regex: '[A-Z]{3}-\\d{3}' },
		)
		expect(wrapper.vm.generatedKey).toBe('ABC-123')
	})

	it('generate(): server error surfaces in the NcNoteCard', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({
			response: { data: { message: 'Length out of range' } },
		})

		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		await wrapper.vm.generate()

		expect(wrapper.vm.generatedKey).toBe('')
		expect(wrapper.vm.error).toBe('Length out of range')
		expect(wrapper.find('.nc-note-card-stub').exists()).toBe(true)
	})

	it('use(): emits the generated key and closes the dialog', async () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.generatedKey = 'preview-key'
		wrapper.vm.use()

		const emitted = wrapper.emitted('generated')
		expect(emitted).toBeTruthy()
		expect(emitted[0]).toEqual(['preview-key'])

		const update = wrapper.emitted('update:open')
		expect(update).toBeTruthy()
		expect(update[update.length - 1]).toEqual([false])
	})

	it('use(): no-op when no key has been generated yet', () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.use()

		expect(wrapper.emitted('generated')).toBeFalsy()
	})

	it('reset() clears the preview and error when the dialog closes', () => {
		const wrapper = mount(KeyGeneratorModal, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.generatedKey = 'old-key'
		wrapper.vm.error = 'boom'
		wrapper.vm.onUpdateOpen(false)

		expect(wrapper.vm.generatedKey).toBe('')
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.loading).toBe(false)
	})
})
