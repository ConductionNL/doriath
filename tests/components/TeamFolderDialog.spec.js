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

/**
 * Mock the GETs the dialog issues on open: team-folder list, reconcile, and
 * the suites list behind the member picker's candidates.
 */
function mockApi({ owned = [], missing = [], suites = [] } = {}) {
	vi.spyOn(axios, 'get').mockImplementation((url) => {
		if (url.includes('/reconcile')) {
			return Promise.resolve({
				data: { secrets: [], recipients: [], missing },
			})
		}
		if (url.includes('/suites')) {
			return Promise.resolve({ data: suites })
		}
		return Promise.resolve({ data: { owned, memberOf: [] } })
	})
}

describe('TeamFolderDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		// Where @nextcloud/auth reads the session user from. The member picker
		// drops the current user from its candidates — they already own the
		// folder — and that is also what keeps today's own-suites-only response
		// from listing a single bogus candidate.
		document.head.setAttribute('data-user', 'me')
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

	it('asks for an id by hand while no suite owners can be listed', async () => {
		// What the server does TODAY: /suites answers with the caller's own
		// suites, so there is nobody to offer and the field must stay usable.
		mockApi({
			owned: [{ id: 'tf-1', folderId: 'folder-1', members: [] }],
			suites: [{ ownerType: 'user', ownerId: 'me', status: 'active' }],
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.refresh()
		await flush()

		expect(wrapper.vm.memberCandidates).toEqual([])
		expect(wrapper.find('[data-testid="team-folder-member-id"]').exists()).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="team-folder-member-select"]').exists(),
		).toBe(false)
	})

	it('offers a picker of suite owners once the endpoint reports them', async () => {
		// What the same code does once /suites can answer for everyone: no
		// change here beyond the response.
		mockApi({
			owned: [
				{
					id: 'tf-1',
					folderId: 'folder-1',
					members: [{ id: 'm1', memberType: 'user', memberId: 'bob' }],
				},
			],
			suites: [
				{ ownerType: 'user', ownerId: 'carol', status: 'active' },
				// Already a member — offering them again invites a no-op.
				{ ownerType: 'user', ownerId: 'bob', status: 'active' },
				// Revoked: no key to encrypt to, so the copies could never
				// be created.
				{ ownerType: 'user', ownerId: 'dave', status: 'revoked' },
				// Not a user: a suite can be owned by other things.
				{ ownerType: 'group', ownerId: 'devops', status: 'active' },
			],
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.refresh()
		await flush()

		expect(wrapper.vm.memberCandidates).toEqual(['carol'])
		expect(
			wrapper.find('[data-testid="team-folder-member-select"]').exists(),
		).toBe(true)

		// Groups have no source yet, so that half stays a text field.
		wrapper.vm.newMemberType = 'group'
		await flush()
		expect(wrapper.vm.memberCandidates).toEqual([])
		expect(wrapper.find('[data-testid="team-folder-member-id"]').exists()).toBe(
			true,
		)
	})

	it('clears a picked id when the member type changes', async () => {
		mockApi({ owned: [{ id: 'tf-1', folderId: 'folder-1', members: [] }] })
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.refresh()
		await flush()

		wrapper.vm.newMemberId = 'bob'
		wrapper.vm.newMemberType = 'group'
		await flush()

		// A user id is not a group id: carrying it across offered to add
		// "bob" as a group.
		expect(wrapper.vm.newMemberId).toBe('')
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
