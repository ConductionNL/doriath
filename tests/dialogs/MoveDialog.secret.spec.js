/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/MoveDialog.vue` with subject="secret".
 *
 * SecretMoveDialog had no component test of its own; merging it into the one
 * move dialog is the moment to add one, because the risk of a merged dialog is
 * precisely that one subject's behaviour bleeds into the other. So these tests
 * pin the secret path AND the separation:
 *
 *  - Moving a secret is ONE metadata-only PUT. It must not fetch children, not
 *    page the whole vault, and not run the non-atomic per-item loop that the
 *    vault subject needs.
 *  - The vault-only chrome (the emptiness hint, the failure list) must not
 *    appear, and `isEmpty` must never disable a secret move — a secret has no
 *    contents to be empty of.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MoveDialog from '../../src/dialogs/MoveDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

/**
 * Mount the dialog over a secret, with the library chrome stubbed out.
 *
 * @param {object} propsData Extra props to merge over the defaults.
 * @return {object} The wrapper.
 */
function mountDialog(propsData = {}) {
	return mount(MoveDialog, {
		propsData: {
			subject: 'secret',
			secretId: 'secret-1',
			currentFolderId: 'folder-a',
			...propsData,
		},
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcNoteCard: { template: '<div class="note"><slot /></div>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				DestinationSelect: { template: '<div class="stub-picker" />' },
				NcLoadingIcon: true,
				FolderMove: true,
			},
		},
	})
}

describe('MoveDialog — subject="secret"', () => {
	let secretStore
	let folderStore

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		secretStore = useSecretStore()
		folderStore = useFolderStore()
		folderStore.folders = []
		folderStore.fetchChildren = vi.fn()
		folderStore.updateFolder = vi.fn()
	})

	it('preselects the folder the secret is already in', () => {
		expect(mountDialog().vm.target).toBe('folder-a')
	})

	it('moves the secret with a single metadata-only update', async () => {
		const update = vi
			.spyOn(secretStore, 'updateSecret')
			.mockResolvedValue({ id: 'secret-1', folderId: 'folder-b' })

		const wrapper = mountDialog()
		wrapper.vm.target = 'folder-b'
		await wrapper.vm.submit()

		expect(update).toHaveBeenCalledTimes(1)
		expect(update).toHaveBeenCalledWith('secret-1', { folderId: 'folder-b' })
		expect(wrapper.emitted('saved')[0][0].folderId).toBe('folder-b')
	})

	it('calls the host callback and closes on success', async () => {
		vi.spyOn(secretStore, 'updateSecret').mockResolvedValue({ id: 'secret-1' })
		const onSaved = vi.fn()

		const wrapper = mountDialog({ onSaved })
		wrapper.vm.target = 'folder-b'
		await wrapper.vm.submit()

		expect(onSaved).toHaveBeenCalled()
		expect(wrapper.vm.open).toBe(false)
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	// A refused move that closed the dialog would look exactly like one that
	// worked, and the secret would still be where it was.
	it('stays open with the reason when the move is refused', async () => {
		vi.spyOn(secretStore, 'updateSecret').mockRejectedValue({
			response: { status: 403, data: { message: 'Not the owner' } },
		})

		const wrapper = mountDialog()
		wrapper.vm.target = 'folder-b'
		await wrapper.vm.submit()

		expect(wrapper.vm.error).toBe('Not the owner')
		expect(wrapper.vm.open).toBe(true)
		expect(wrapper.emitted('saved')).toBeFalsy()
	})

	// THE RISK OF MERGING: the vault subject's mechanism must not touch this
	// path. One PUT, no preflight, no per-item loop.
	it('never runs the vault-contents machinery', async () => {
		const update = vi
			.spyOn(secretStore, 'updateSecret')
			.mockResolvedValue({ id: 'secret-1' })
		const fetchAll = vi.spyOn(secretStore, 'fetchAllSecrets')

		const wrapper = mountDialog()
		wrapper.vm.target = 'folder-b'
		await wrapper.vm.submit()

		expect(folderStore.fetchChildren).not.toHaveBeenCalled()
		expect(fetchAll).not.toHaveBeenCalled()
		expect(folderStore.updateFolder).not.toHaveBeenCalled()
		expect(update).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.failures).toEqual([])
	})

	it('shows none of the vault-only chrome', () => {
		const wrapper = mountDialog()

		// The emptiness hint belongs to a vault, which has contents; a secret
		// does not, so `isEmpty` must never gate its Move button.
		expect(wrapper.vm.isVault).toBe(false)
		expect(wrapper.vm.isEmpty).toBe(false)
		expect(wrapper.text()).not.toContain('This vault is empty')
	})

	it('offers no destination exclusion — any vault or folder will do', () => {
		expect(mountDialog().vm.excludeId).toBeNull()
	})

	// Review follow-up (#600): the picker preselects the CURRENT folder so
	// "you are here" is visible, which used to arm Move on open — clicking
	// it fired a PUT that kept the secret exactly where it was.
	it('keeps Move disarmed until the destination actually changes', async () => {
		const update = vi.spyOn(secretStore, 'updateSecret')

		const wrapper = mountDialog()
		expect(wrapper.vm.target).toBe('folder-a')
		expect(wrapper.vm.canSubmit).toBe(false)

		await wrapper.vm.submit()
		expect(update).not.toHaveBeenCalled()

		wrapper.vm.target = 'folder-b'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('will not submit without a destination', async () => {
		const update = vi.spyOn(secretStore, 'updateSecret')

		const wrapper = mountDialog({ currentFolderId: null })
		expect(wrapper.vm.canSubmit).toBe(false)

		await wrapper.vm.submit()
		expect(update).not.toHaveBeenCalled()
	})
})
