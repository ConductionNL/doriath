/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/modals/SubfolderResolutionDialog.vue`.
 *
 * The resolution dialog gathers the user's per-subfolder action plan
 * (keep / move / delete) before a non-empty folder is deleted. It MUST:
 *  - on mount, seed `plan` with the children payload — one entry per
 *    subfolder, defaulting to 'keep'
 *  - re-seed `plan` when the `children` prop updates (e.g. parent fetches
 *    children async after the user opens the dialog)
 *  - on submit, dispatch useFolderStore().deleteFolder with the
 *    resolution body { directSecrets, subfolders: { id: action } }
 *  - on submit success, emit `deleted` with the folder id AND close
 *    the dialog (update:open=false)
 *  - on submit failure, surface error.response.data.message in `error`
 *    AND keep the dialog open so the user can retry
 *  - cancel button closes the dialog without calling deleteFolder
 *
 * @spec openspec/changes/implement-secrets/tasks.md#7.5
 * @spec openspec/changes/implement-secrets/tasks.md#13.3
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SubfolderResolutionDialog from '../../src/modals/SubfolderResolutionDialog.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'

const CHILDREN_PAYLOAD = {
	directSecretCount: 3,
	subfolders: [
		{ id: 'sub-a', name: 'Subproject A', secretCount: 2 },
		{ id: 'sub-b', name: 'Subproject B', secretCount: 5 },
	],
}

describe('SubfolderResolutionDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('seeds `plan` from children on mount (default keep per subfolder)', async () => {
		const wrapper = mount(SubfolderResolutionDialog, {
			propsData: {
				open: true,
				folderId: 'f-parent',
				children: CHILDREN_PAYLOAD,
			},
		})

		await wrapper.vm.$nextTick()

		expect(wrapper.vm.plan).toEqual({
			'sub-a': 'keep',
			'sub-b': 'keep',
		})
		expect(wrapper.vm.directSecrets).toBe('move')
	})

	it('re-seeds `plan` when the `children` prop updates with new subfolders', async () => {
		const wrapper = mount(SubfolderResolutionDialog, {
			propsData: {
				open: true,
				folderId: 'f-parent',
				children: { directSecretCount: 0, subfolders: [] },
			},
		})

		await wrapper.vm.$nextTick()
		expect(wrapper.vm.plan).toEqual({})

		await wrapper.setProps({ children: CHILDREN_PAYLOAD })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.plan).toEqual({
			'sub-a': 'keep',
			'sub-b': 'keep',
		})
	})

	it('submit(): dispatches deleteFolder with the resolution body and emits `deleted`', async () => {
		const folderStore = useFolderStore()
		folderStore.deleteFolder = vi.fn().mockResolvedValue()

		const wrapper = mount(SubfolderResolutionDialog, {
			propsData: {
				open: true,
				folderId: 'f-parent',
				children: CHILDREN_PAYLOAD,
			},
		})

		await wrapper.vm.$nextTick()

		// Override the user's plan with a non-default mix.
		wrapper.vm.plan = { 'sub-a': 'delete', 'sub-b': 'move' }
		wrapper.vm.directSecrets = 'delete'

		await wrapper.vm.submit()

		expect(folderStore.deleteFolder).toHaveBeenCalledWith('f-parent', {
			directSecrets: 'delete',
			subfolders: { 'sub-a': 'delete', 'sub-b': 'move' },
		})

		expect(wrapper.emitted('deleted')).toBeTruthy()
		expect(wrapper.emitted('deleted')[0]).toEqual(['f-parent'])

		// And the dialog closes.
		expect(wrapper.emitted('update:open')).toBeTruthy()
		expect(wrapper.emitted('update:open').pop()).toEqual([false])
	})

	it('submit(): surfaces the server error message AND keeps the dialog open', async () => {
		const folderStore = useFolderStore()
		folderStore.deleteFolder = vi.fn().mockRejectedValue({
			response: { data: { message: 'folder still locked' } },
		})

		const wrapper = mount(SubfolderResolutionDialog, {
			propsData: {
				open: true,
				folderId: 'f-parent',
				children: CHILDREN_PAYLOAD,
			},
		})

		await wrapper.vm.$nextTick()
		await wrapper.vm.submit()

		expect(wrapper.vm.error).toBe('folder still locked')
		expect(wrapper.vm.loading).toBe(false)
		// `update:open=false` must NOT have fired on failure.
		const closeEvents = wrapper.emitted('update:open') || []
		expect(closeEvents).toEqual([])
		expect(wrapper.emitted('deleted')).toBeFalsy()
	})

	it('submit(): falls back to a translated default message when the error has no body', async () => {
		const folderStore = useFolderStore()
		folderStore.deleteFolder = vi.fn().mockRejectedValue(new Error('network'))

		const wrapper = mount(SubfolderResolutionDialog, {
			propsData: {
				open: true,
				folderId: 'f-parent',
				children: CHILDREN_PAYLOAD,
			},
		})

		await wrapper.vm.$nextTick()
		await wrapper.vm.submit()

		expect(wrapper.vm.error).toBe('Failed to delete folder')
	})

	it('onUpdateOpen(false): emits update:open and does NOT call deleteFolder', async () => {
		const folderStore = useFolderStore()
		folderStore.deleteFolder = vi.fn().mockResolvedValue()

		const wrapper = mount(SubfolderResolutionDialog, {
			propsData: {
				open: true,
				folderId: 'f-parent',
				children: CHILDREN_PAYLOAD,
			},
		})

		await wrapper.vm.$nextTick()
		wrapper.vm.onUpdateOpen(false)

		expect(folderStore.deleteFolder).not.toHaveBeenCalled()
		expect(wrapper.emitted('update:open')).toBeTruthy()
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
		expect(wrapper.emitted('deleted')).toBeFalsy()
	})
})
