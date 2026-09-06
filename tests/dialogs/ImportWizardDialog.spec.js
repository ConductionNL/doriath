/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the import wizard dialog (secret-import §4).
 *
 * Locks down:
 *  - A locked vault blocks the wizard (lock guard) and the file pick reads
 *    nothing.
 *  - The mapping step cannot proceed without at least one parsed row.
 *  - Abandoning before commit creates nothing and resets the store.
 *
 * Runs under jsdom with lightweight @nextcloud/vue stubs.
 *
 * @spec openspec/changes/secret-import/specs/secret-import/spec.md
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ImportWizardDialog from '../../src/dialogs/ImportWizardDialog.vue'
import { useImportStore } from '../../src/store/modules/import.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['type', 'disabled'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'],
		template: '<div />',
	},
	NcPasswordField: {
		props: ['value', 'label'],
		template: '<input type="password" />',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		template: '<label><slot /></label>',
	},
	NcLoadingIcon: { props: ['size'], template: '<div />' },
	NcEmptyContent: { props: ['name'], template: '<div><slot /></div>' },
}

const mountOpts = { global: { stubs: ncStubs, mocks: { t: (app, s, _vars) => s } } }

describe('ImportWizardDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('shows the lock guard and reads no file when the vault is locked', async () => {
		const session = useSessionStore()
		session.cryptoKey = null // locked
		const wrapper = mount(ImportWizardDialog, {
			propsData: { open: true },
			...mountOpts,
		})
		const store = useImportStore()
		const parseSpy = vi.spyOn(store, 'parseFile')

		expect(wrapper.vm.locked).toBe(true)
		// A file pick while locked must not read/parse.
		await wrapper.vm.onFilePicked({
			target: {
				files: [
					{
						text: async () => 'x',
						slice: () => ({
							arrayBuffer: async () => new Uint8Array(4).buffer,
						}),
					},
				],
			},
		})
		expect(parseSpy).not.toHaveBeenCalled()
	})

	it('cannot proceed from mapping without at least one parsed row', async () => {
		useSessionStore().cryptoKey = { fake: true }
		const wrapper = mount(ImportWizardDialog, {
			propsData: { open: true },
			...mountOpts,
		})
		const store = useImportStore()
		store.goToStep('mapping')
		store.rows = []
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canProceed).toBe(false)

		store.rows = [{ sourceRow: 1, name: 'X', errors: [] }]
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canProceed).toBe(true)
	})

	it('abandoning before commit resets the store and creates nothing', async () => {
		useSessionStore().cryptoKey = { fake: true }
		const wrapper = mount(ImportWizardDialog, {
			propsData: { open: true },
			...mountOpts,
		})
		const store = useImportStore()
		store.goToStep('mapping')
		store.rows = [{ sourceRow: 1, name: 'X', errors: [] }]

		wrapper.vm.onUpdateOpen(false)
		expect(store.rows).toEqual([])
		expect(store.step).toBe('pick')
		expect(store.summary).toBeNull()
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
	})

	it('detects a KDBX file on pick and shows guidance instead of parsing', async () => {
		useSessionStore().cryptoKey = { fake: true }
		const wrapper = mount(ImportWizardDialog, {
			propsData: { open: true },
			...mountOpts,
		})
		const store = useImportStore()
		const parseSpy = vi.spyOn(store, 'parseFile')

		const kdbxHead = new Uint8Array([0x9a, 0xa2, 0xd9, 0x03]).buffer
		await wrapper.vm.onFilePicked({
			target: {
				files: [
					{
						slice: () => ({ arrayBuffer: async () => kdbxHead }),
						text: async () => '',
					},
				],
			},
		})
		expect(wrapper.vm.kdbxDetected).toBe(true)
		expect(parseSpy).not.toHaveBeenCalled()
	})
})
