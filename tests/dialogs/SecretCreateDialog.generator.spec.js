/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Integration test for the KeyGeneratorModal wiring inside
 * `src/dialogs/SecretCreateDialog.vue`.
 *
 * What this locks down:
 *  - the create dialog renders a generator button (dice icon) next to the
 *    value field — clicking it opens the embedded KeyGeneratorModal;
 *  - the dialog listens for the modal's `generated` event and writes the
 *    emitted key into the local `value` field (i.e. the password input);
 *  - re-emitting `update:open=false` after Use closes the embedded modal.
 *
 * @spec openspec/changes/implement-key-generator/tasks.md#4.1
 * @spec openspec/changes/implement-key-generator/tasks.md#4.2
 * @spec openspec/changes/implement-key-generator/tasks.md#4.3
 * @spec openspec/changes/implement-key-generator/tasks.md#8.3
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template:
			'<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['type', 'disabled', 'title', 'ariaLabel'],
		template:
			'<button :disabled="disabled" :title="title" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'value'],
		template: '<div class="nc-select-stub"><slot /></div>',
	},
	NcTextField: {
		props: ['value', 'label', 'required'],
		template: '<input class="nc-text-stub" :value="value" />',
	},
	NcPasswordField: {
		props: ['value', 'label'],
		template:
			'<input type="password" class="nc-password-stub" :value="value" />',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="nc-note-card-stub" :data-type="type"><slot /></div>',
	},
	NcLoadingIcon: { template: '<span class="nc-loading-stub" />' },
	Plus: { template: '<i class="icon-plus" />' },
	Dice5: { template: '<i class="icon-dice5" />' },
	KeyGeneratorModal: {
		props: ['open'],
		template:
			'<div class="key-generator-modal-stub" :data-open="open"><slot /></div>',
	},
}

describe('SecretCreateDialog — KeyGeneratorModal wiring', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		// Pre-seed the dependent stores so `mounted()` does not fetch.
		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = []
		folderStore.fetchFolders = vi.fn().mockResolvedValue()
		const sessionStore = useSessionStore()
		sessionStore.isLocked = false
	})

	it('openGenerator() opens the embedded KeyGeneratorModal', async () => {
		const wrapper = mount(SecretCreateDialog, {
			propsData: {},
			global: { stubs: ncStubs },
		})

		await wrapper.vm.$nextTick()

		expect(wrapper.vm.generatorOpen).toBe(false)

		wrapper.vm.openGenerator()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.generatorOpen).toBe(true)
		expect(wrapper.find('.key-generator-modal-stub').exists()).toBe(true)
	})

	it('onGenerated(): writes the emitted key into the value field', async () => {
		const wrapper = mount(SecretCreateDialog, {
			propsData: {},
			global: { stubs: ncStubs },
		})

		await wrapper.vm.$nextTick()

		expect(wrapper.vm.value).toBe('')

		wrapper.vm.onGenerated('generated-secret-42')

		expect(wrapper.vm.value).toBe('generated-secret-42')
	})

	it('onGenerated() ignores empty / non-string payloads', async () => {
		const wrapper = mount(SecretCreateDialog, {
			propsData: {},
			global: { stubs: ncStubs },
		})

		await wrapper.vm.$nextTick()
		wrapper.vm.value = 'existing-key'

		wrapper.vm.onGenerated('')
		expect(wrapper.vm.value).toBe('existing-key')

		wrapper.vm.onGenerated(null)
		expect(wrapper.vm.value).toBe('existing-key')

		wrapper.vm.onGenerated(undefined)
		expect(wrapper.vm.value).toBe('existing-key')
	})
})
