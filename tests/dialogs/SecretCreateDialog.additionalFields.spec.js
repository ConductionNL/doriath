/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Additional fields on the create dialog.
 *
 * Before this change the dialog referenced additional fields nowhere, so an owner
 * could only obtain one by asking a stranger to fill a secret request. What is
 * pinned here is that the members the user typed actually reach the store call —
 * and that a secret created WITHOUT any does not get an empty blob written onto it
 * for no reason.
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
		emits: ['update:modelValue'],
		template:
			'<input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
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

async function mountDialog() {
	const wrapper = mount(SecretCreateDialog, {
		// A folder is required since the picker lost its "Vault root" option
		// (secrets cannot live at the root). These scenarios are about the
		// additional fields, so any folder will do.
		propsData: { folderId: 'folder-1' },
		global: { stubs },
	})
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('SecretCreateDialog — additional fields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()

		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const folderStore = useFolderStore()
		folderStore.folders = []
		folderStore.fetchFolders = vi.fn().mockResolvedValue()
		// isLocked is a GETTER over cryptoKey — assigning it is a no-op (Vue logs
		// "target is readonly"), so the vault would stay locked and canSubmit would
		// never become true. Set the state the getter actually reads.
		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	it('offers the editor', async () => {
		const wrapper = await mountDialog()

		expect(
			wrapper.find('[data-testid="additional-fields-editor"]').exists(),
		).toBe(true)
	})

	it('sends the members the user added, as one object', async () => {
		const wrapper = await mountDialog()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.name = 'Supplier API'
		wrapper.vm.value = 'Xk9#mQ2$vL7@pR4!zT6&'
		wrapper.vm.additionalFields = [
			{ name: 'client-id', value: 'acme-4711' },
			{ name: 'tenant', value: 'acme' },
		]
		await wrapper.vm.submit()

		expect(create).toHaveBeenCalledTimes(1)
		expect(create.mock.calls[0][0].additionalFields).toEqual({
			'client-id': 'acme-4711',
			tenant: 'acme',
		})
	})

	it('omits the field entirely when the user added none', async () => {
		// Not `{}`: the store encrypts whatever it is handed, so sending an empty
		// object unconditionally would write a pointless ciphertext blob onto every
		// secret created here.
		const wrapper = await mountDialog()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.name = 'Plain secret'
		wrapper.vm.value = 'Xk9#mQ2$vL7@pR4!zT6&'
		await wrapper.vm.submit()

		expect('additionalFields' in create.mock.calls[0][0]).toBe(false)
	})

	it('drops a member whose name was left blank', async () => {
		const wrapper = await mountDialog()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.name = 'Supplier API'
		wrapper.vm.value = 'Xk9#mQ2$vL7@pR4!zT6&'
		wrapper.vm.additionalFields = [
			{ name: '', value: 'orphan' },
			{ name: 'kept', value: '1' },
		]
		await wrapper.vm.submit()

		expect(create.mock.calls[0][0].additionalFields).toEqual({ kept: '1' })
	})

	// ---------------------------------------------------------------------------
	// Two scenarios this change's delta re-declares which nothing covered before.
	// They were moved off PR #270 as a known gap rather than waived with a false
	// exclude; this is where they get paid for. Both are dialog-level rules, so
	// vitest is the honest home — a browser adds nothing to "the button is
	// disabled" that this does not already prove.
	// ---------------------------------------------------------------------------

	it('requires a name AND a value before anything is sent', async () => {
		const wrapper = await mountDialog()
		const create = vi
			.spyOn(useSecretStore(), 'createSecret')
			.mockResolvedValue({ id: 's1' })

		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.name = 'Has a name'
		expect(wrapper.vm.canSubmit).toBe(false)

		wrapper.vm.name = ''
		wrapper.vm.value = 'Xk9#mQ2$vL7@pR4!zT6&'
		expect(wrapper.vm.canSubmit).toBe(false)

		// Whitespace is not a name.
		wrapper.vm.name = '   '
		expect(wrapper.vm.canSubmit).toBe(false)

		await wrapper.vm.submit()
		expect(create).not.toHaveBeenCalled()

		wrapper.vm.name = 'Both present'
		expect(wrapper.vm.canSubmit).toBe(true)
	})
})
