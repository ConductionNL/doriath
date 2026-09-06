/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/modals/TeamFolderDialog.vue`.
 *
 * @spec openspec/changes/team-folder-sharing/tasks.md#6.1
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TeamFolderDialog from '../../src/modals/TeamFolderDialog.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/** Mock the two GETs the dialog issues on open: list + reconcile. */
function mockApi({ owned = [], missing = [] } = {}) {
	vi.spyOn(axios, 'get').mockImplementation((url) => {
		if (url.includes('/reconcile')) {
			return Promise.resolve({
				data: { secrets: [], recipients: [], missing },
			})
		}
		return Promise.resolve({ data: { owned, memberOf: [] } })
	})
}

describe('TeamFolderDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('offers to share an unshared folder', async () => {
		mockApi({ owned: [] })
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		await wrapper.setProps({ open: true })
		wrapper.vm.refresh()
		await flush()
		expect(wrapper.find('[data-testid="team-folder-share"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="team-folder-members"]').exists()).toBe(
			false,
		)
	})

	it('renders the member list and add-member controls for a shared folder', async () => {
		mockApi({
			owned: [
				{
					id: 'tf-1',
					folderId: 'folder-1',
					folderName: 'DevOps',
					members: [
						{ id: 'm1', memberType: 'user', memberId: 'bob' },
						{ id: 'm2', memberType: 'group', memberId: 'devops' },
					],
				},
			],
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.refresh()
		await flush()
		expect(wrapper.find('[data-testid="team-folder-members"]').exists()).toBe(
			true,
		)
		expect(wrapper.findAll('.team-folder-dialog__member')).toHaveLength(2)
		expect(wrapper.find('[data-testid="team-folder-add-member"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="team-folder-unshare"]').exists()).toBe(
			true,
		)
	})

	it('shows the needs-reshare warning when the reconcile pass reports missing pairs', async () => {
		mockApi({
			owned: [
				{
					id: 'tf-1',
					folderId: 'folder-1',
					folderName: 'DevOps',
					members: [],
				},
			],
			missing: [
				{ secretId: 'sec-1', userId: 'bob' },
				{ secretId: 'sec-2', userId: 'bob' },
			],
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.refresh()
		await flush()
		expect(
			wrapper.find('[data-testid="team-folder-needs-reshare"]').exists(),
		).toBe(true)
		expect(wrapper.find('[data-testid="team-folder-run-fanout"]').exists()).toBe(
			true,
		)
	})
})
