/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the export + deletion dialogs (secret-export-gdpr §6).
 *
 * What these lock down:
 *  - ExportDialog: the backup path blocks submit below the zxcvbn ≥ 3
 *    passphrase floor and enables it at/above the floor.
 *  - ExportDialog: the plaintext CSV path cannot submit until the warning is
 *    acknowledged AND a master password is entered; a wrong master password is
 *    rejected by the client-side re-auth (no CSV export, no event).
 *  - AccountDeletionDialog: submit is blocked until BOTH a master password and
 *    the exact typed confirmation phrase are present.
 *
 * Runs under jsdom with lightweight stubs for the @nextcloud/vue components.
 *
 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AccountDeletionDialog from '../../src/dialogs/AccountDeletionDialog.vue'
import ExportDialog from '../../src/dialogs/ExportDialog.vue'
import * as reauth from '../../src/crypto/reauth.js'
import { useExportStore } from '../../src/store/modules/export.js'
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
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'value'],
		template: '<div />',
	},
	NcTextField: { props: ['value', 'label'], template: '<input />' },
	NcPasswordField: {
		props: ['value', 'label'],
		template: '<input type="password" />',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value', 'name', 'type'],
		template: '<label><slot /></label>',
	},
}

const mountOpts = {
	global: { stubs: ncStubs, mocks: { t: (app, s, vars) => (vars ? s : s) } },
}

describe('ExportDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('blocks backup submit below the passphrase floor and enables it above', async () => {
		const wrapper = mount(ExportDialog, {
			propsData: { open: true, secrets: [], folders: [] },
			...mountOpts,
		})
		wrapper.vm.mode = 'encrypted-backup'
		wrapper.vm.passphrase = 'pw'
		wrapper.vm.passphraseScore = 1
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.passphrase = 'a-much-longer-unpredictable-passphrase'
		wrapper.vm.passphraseScore = 3
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('plaintext CSV requires warning ack + password and rejects a wrong password', async () => {
		const wrapper = mount(ExportDialog, {
			propsData: { open: true, secrets: [], folders: [] },
			...mountOpts,
		})
		wrapper.vm.mode = 'plaintext-csv'
		await wrapper.vm.$nextTick()
		// No acknowledgement, no password yet.
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.warningAcknowledged = true
		wrapper.vm.masterPassword = 'attempt'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(true)

		// Wrong master password -> re-auth fails -> no CSV export call.
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(false)
		const exportStore = useExportStore()
		const csvSpy = vi.spyOn(exportStore, 'exportCsv').mockResolvedValue()
		useSessionStore().encryptedPrivateKey = 'blob'

		await wrapper.vm.onExport()
		expect(csvSpy).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBeTruthy()
	})
})

describe('AccountDeletionDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('blocks submit until both password and the exact phrase are present', async () => {
		const wrapper = mount(AccountDeletionDialog, {
			propsData: { open: true },
			...mountOpts,
		})
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.masterPassword = 'secret'
		wrapper.vm.confirmation = 'wrong phrase'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.confirmation = 'DELETE MY KEEPIQ DATA'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(true)
	})
})
