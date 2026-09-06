/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two create-dialog rules that nothing covered: required fields, and the folder.
 *
 * Both are declared scenarios of "Create a Secret from the UI" and neither had a
 * test of any kind — no vitest, no Playwright. They were carried across PR #270 and
 * #282 as a known gap rather than waived with an exclude claiming coverage that did
 * not exist; this is where that debt is paid.
 *
 * A note on the harness, because it is the reason a test like this can look like it
 * passes while asserting nothing: `sessionStore.isLocked = false` is a NO-OP. It is
 * a getter over `cryptoKey`, so assigning it does nothing (Vue logs "target is
 * readonly"), the dialog stays locked, `canSubmit` stays false and `submit()`
 * returns before doing any work. Set `cryptoKey` instead.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretCreateDialog from '../../src/dialogs/SecretCreateDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'

/** A value strong enough to satisfy the org password policy. */
const STRONG = 'Xk9#mQ2$vL7@pR4!zT6&'

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'],
		template: '<div />',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		template: '<input :value="modelValue" :disabled="disabled" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" :value="modelValue" />',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

async function mountDialog(propsData = {}) {
	const wrapper = mount(SecretCreateDialog, { propsData, global: { stubs } })
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('SecretCreateDialog — required fields and folder default', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()

		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = []
		folderStore.fetchFolders = vi.fn().mockResolvedValue()

		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	it('requires a name AND a value before anything is sent', async () => {
		const wrapper = await mountDialog()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.name = 'Has a name'
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.name = ''
		wrapper.vm.value = STRONG
		expect(wrapper.vm.canSubmit).toBe(false)

		// Whitespace is not a name.
		wrapper.vm.name = '   '
		expect(wrapper.vm.canSubmit).toBe(false)

		await wrapper.vm.submit()
		expect(create).not.toHaveBeenCalled()

		// Name and value alone are no longer enough: the picker lost its
		// "Vault root" option, so a folder must be chosen too.
		wrapper.vm.name = 'Both present'
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.selectedFolderId = 'folder-1'
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('stays blocked while the vault is locked, however complete the form is', async () => {
		// The requirement's "MUST be blocked while the vault is locked" is enforced
		// here rather than by disabling each field.
		useSessionStore().cryptoKey = null
		const wrapper = await mountDialog()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.name = 'Complete'
		wrapper.vm.value = STRONG

		expect(wrapper.vm.locked).toBe(true)
		expect(wrapper.vm.canSubmit).toBe(false)

		await wrapper.vm.submit()
		expect(create).not.toHaveBeenCalled()
	})

	it('defaults the folder to the one being viewed, and persists it', async () => {
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })
		const wrapper = await mountDialog({ folderId: 'folder-42' })

		expect(wrapper.vm.selectedFolderId).toBe('folder-42')

		wrapper.vm.name = 'In a folder'
		wrapper.vm.value = STRONG
		await wrapper.vm.submit()

		expect(create.mock.calls[0][0].folderId).toBe('folder-42')
	})

	// This used to assert the opposite — "sends a null folder when created at
	// the vault root". The root is not a place a secret can live (top-level
	// folders are Vaults; a rootless secret has nowhere to be shown), so the
	// picker no longer offers it and a folderless form must stay blocked.
	it('refuses to create at the vault root — a folder must be chosen', async () => {
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })
		const wrapper = await mountDialog()

		wrapper.vm.name = 'At the root'
		wrapper.vm.value = STRONG

		expect(wrapper.vm.canSubmit).toBe(false)
		await wrapper.vm.submit()
		expect(create).not.toHaveBeenCalled()
	})
})
