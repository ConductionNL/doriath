/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/MoveDialog.vue` with subject="vault".
 *
 * There is no bulk move endpoint, so "move vault contents" is a client-driven
 * loop and cannot be atomic. What these tests pin is the two things that make
 * a non-atomic move survivable:
 *
 *  - The subfolder set comes from the SERVER (`fetchChildren`), not from
 *    `folderStore.folders`. That list is hydrated on nav mount and on
 *    vault-unlock, so a subfolder created since — another tab, another device —
 *    is absent from it, and a store-sourced move would silently leave it behind.
 *  - One item failing does not abandon the rest. Every item is attempted, and
 *    the ones that stayed behind are NAMED, so the user can see which vault
 *    holds what and simply move again.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MoveDialog from '../../src/dialogs/MoveDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

/**
 * Mount the dialog with the library chrome stubbed out.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(MoveDialog, {
		propsData: {
			subject: 'vault',
			folder: { id: 'source-vault', name: 'Source' },
		},
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcNoteCard: { template: '<div class="note"><slot /></div>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				NcSelect: { template: '<div />' },
				DestinationSelect: { template: '<div />' },
				NcLoadingIcon: true,
				FolderMove: true,
			},
		},
	})
}

describe('FolderMoveDialog', () => {
	let folderStore
	let secretStore

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()

		folderStore = useFolderStore()
		secretStore = useSecretStore()

		// The store list is deliberately EMPTY while the server reports a
		// subfolder: a store-sourced move would skip it, a server-sourced
		// one moves it.
		folderStore.folders = []
		vi.spyOn(folderStore, 'fetchChildren').mockResolvedValue({
			directSecretCount: 2,
			subfolders: [{ id: 'sub-1', name: 'Fresh subfolder' }],
		})
		vi.spyOn(folderStore, 'updateFolder').mockResolvedValue(undefined)

		vi.spyOn(secretStore, 'fetchAllSecrets').mockResolvedValue([
			{ id: 'secret-1', name: 'First' },
			{ id: 'secret-2', name: 'Second' },
		])
		vi.spyOn(secretStore, 'fetchSecrets').mockResolvedValue(undefined)
	})

	it('moves a subfolder the store has not heard of yet', async () => {
		vi.spyOn(secretStore, 'updateSecret').mockResolvedValue(undefined)

		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()
		wrapper.vm.target = 'target-vault'

		await wrapper.vm.submit()

		expect(folderStore.updateFolder).toHaveBeenCalledWith('sub-1', {
			parentId: 'target-vault',
			move: true,
		})
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('carries on past a failed item and names what stayed behind', async () => {
		vi.spyOn(secretStore, 'updateSecret').mockImplementation((id) =>
			id === 'secret-1'
				? Promise.reject(new Error('403 on a delegated secret'))
				: Promise.resolve(undefined),
		)

		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()
		wrapper.vm.target = 'target-vault'

		await wrapper.vm.submit()

		// The failure did not abort the run: the second secret and the
		// subfolder were both still attempted.
		expect(secretStore.updateSecret).toHaveBeenCalledTimes(2)
		expect(folderStore.updateFolder).toHaveBeenCalledTimes(1)

		// And the one that stayed behind is named, with the server's reason.
		expect(wrapper.vm.failures).toEqual([
			{
				id: 'secret-1',
				name: 'First',
				message: '403 on a delegated secret',
			},
		])
		expect(wrapper.text()).toContain('First')
		expect(wrapper.text()).toContain('403 on a delegated secret')

		// A partial move is not a save, and the dialog stays open so the
		// list remains readable.
		expect(wrapper.emitted('saved')).toBeFalsy()
		expect(wrapper.vm.open).toBe(true)
	})

	it('reports that nothing moved when the contents cannot be read', async () => {
		folderStore.fetchChildren.mockRejectedValue(new Error('network down'))
		vi.spyOn(secretStore, 'updateSecret').mockResolvedValue(undefined)

		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()
		wrapper.vm.children = { directSecretCount: 2, subfolders: [] }
		wrapper.vm.target = 'target-vault'

		await wrapper.vm.submit()

		expect(secretStore.updateSecret).not.toHaveBeenCalled()
		expect(folderStore.updateFolder).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBeTruthy()
		expect(wrapper.emitted('saved')).toBeFalsy()
	})

	// Review follow-up (#600): closing the dialog used to be cosmetic — the
	// per-item loop kept PUTting invisibly until every item had moved, and
	// the trailing refresh landed on whatever the user navigated to.
	it('stops the transfer when the dialog is dismissed mid-loop', async () => {
		const resolvers = []
		vi.spyOn(secretStore, 'updateSecret').mockImplementation(
			() =>
				new Promise((resolve) => {
					resolvers.push(resolve)
				}),
		)

		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()
		wrapper.vm.target = 'target-vault'

		const run = wrapper.vm.submit()
		await vi.waitFor(() =>
			expect(secretStore.updateSecret).toHaveBeenCalledTimes(1),
		)

		// The user dismisses while the first PUT is still in flight; the
		// list is put back immediately with the store's current filters.
		wrapper.vm.onUpdateOpen(false)
		expect(secretStore.fetchSecrets).toHaveBeenCalledTimes(1)

		resolvers[0]()
		await run

		// The second secret and the subfolder are never touched, nothing is
		// reported as saved, and the loop's finally does not fire a second
		// stale refresh.
		expect(secretStore.updateSecret).toHaveBeenCalledTimes(1)
		expect(folderStore.updateFolder).not.toHaveBeenCalled()
		expect(secretStore.fetchSecrets).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('saved')).toBeFalsy()
	})
})
