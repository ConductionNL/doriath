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
 * Mock the calls the dialog issues on open: the team-folder list, reconcile,
 * the provisioning API behind the group candidates, Nextcloud's sharee search
 * behind the user candidates, and keepiq's shareability probe over them.
 *
 * @param {object}        [options]           Fixture options.
 * @param {Array<object>} [options.owned]     Team folders the user owns.
 * @param {Array<object>} [options.missing]   Reconcile's missing pairs.
 * @param {Array<string>} [options.groups]    Group ids the server returns.
 * @param {Array}         [options.sharees]   Users the sharee search returns —
 *   a user id, or a `[userId, displayName]` pair.
 * @param {Array<string>} [options.shareable] Which of those hold a suite.
 * @param {string}        [options.failing]   A URL fragment whose request
 *   rejects, for the paths where the lookup itself fails.
 */
function mockApi({
	owned = [],
	missing = [],
	groups = [],
	sharees = [],
	shareable = [],
	failing = null,
} = {}) {
	vi.spyOn(axios, 'get').mockImplementation((url) => {
		if (failing !== null && url.includes(failing)) {
			return Promise.reject(new Error('directory down'))
		}
		if (url.includes('/reconcile')) {
			return Promise.resolve({
				data: { secrets: [], recipients: [], missing },
			})
		}
		if (url.includes('cloud/groups')) {
			// The OCS envelope, as the provisioning API actually answers it.
			return Promise.resolve({ data: { ocs: { data: { groups } } } })
		}
		if (url.includes('sharees')) {
			return Promise.resolve({
				data: {
					ocs: {
						data: {
							exact: { users: [] },
							users: sharees.map((entry) => {
								const [userId, label] = Array.isArray(entry)
									? entry
									: [entry, entry]
								return {
									label,
									value: { shareType: 0, shareWith: userId },
								}
							}),
						},
					},
				},
			})
		}
		return Promise.resolve({ data: { owned, memberOf: [] } })
	})

	vi.spyOn(axios, 'post').mockImplementation((url, body) => {
		if (url.includes('recipient-certificates')) {
			// Order preserved and every id answered — shareable ones with a
			// certificate, the rest with the one reason the server gives.
			return Promise.resolve({
				data: {
					recipients: (body?.userIds ?? []).map((userId) =>
						shareable.includes(userId)
							? { userId, shareable: true, certificate: 'PEM' }
							: {
									userId,
									shareable: false,
									reason: 'no_active_suite',
								},
					),
				},
			})
		}
		return Promise.resolve({ data: {} })
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

	it('stays a picker when there is nobody left to add', async () => {
		// The last candidate being taken must not swap the control out from
		// under the user: an empty list is a state of the picker, not a reason
		// to become a different field. NcSelect says "No results" for it.
		mockApi({
			owned: [
				{
					id: 'tf-1',
					folderId: 'folder-1',
					members: [{ id: 'm1', memberType: 'user', memberId: 'carol' }],
				},
			],
			sharees: ['carol'],
			shareable: ['carol'],
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.refresh()
		await flush()

		expect(wrapper.vm.memberCandidates).toEqual([])
		expect(
			wrapper.find('[data-testid="team-folder-member-select"]').exists(),
		).toBe(true)
		// There is no free-text form of this field any more: an id typed by
		// hand is either already listed or cannot be a member at all.
		expect(wrapper.find('[data-testid="team-folder-member-id"]').exists()).toBe(
			false,
		)
	})

	it('offers only the sharees who can receive a secret, by display name', async () => {
		mockApi({
			owned: [
				{
					id: 'tf-1',
					folderId: 'folder-1',
					members: [{ id: 'm1', memberType: 'user', memberId: 'bob' }],
				},
			],
			sharees: [
				['carol', 'Carol Danvers'],
				['bob', 'Bob Vance'],
				['dave', 'Dave Lister'],
			],
			shareable: ['carol', 'bob'],
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.refresh()
		await flush()

		// dave holds no active suite, so there is no key to encrypt a copy to;
		// bob holds one but is already a member. The option carries the name
		// the picker shows and the id it submits — an SSO instance's ids are
		// GUIDs, so a list of them would be a list nobody can read.
		expect(wrapper.vm.memberCandidates).toEqual([
			{ id: 'carol', label: 'Carol Danvers' },
		])
		expect(
			wrapper.find('[data-testid="team-folder-member-select"]').exists(),
		).toBe(true)
	})

	it('says so when the lookup itself failed, instead of an empty list', async () => {
		// A 500 from the probe, an OCS 401, and "nobody matches your term" all
		// render as the same empty picker. Only the last is something the
		// picker can say by itself.
		mockApi({
			owned: [{ id: 'tf-1', folderId: 'folder-1', members: [] }],
			failing: 'sharees',
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.refresh()
		await flush()

		expect(wrapper.vm.memberCandidates).toEqual([])
		expect(wrapper.vm.candidatesError).toBe('Could not reach the directory')

		// The membership list is still the dialog's subject and still correct,
		// so the failure must not have become the dialog's error.
		expect(wrapper.vm.error).toBeNull()
		expect(
			wrapper.find('[data-testid="team-folder-member-select"]').exists(),
		).toBe(true)

		// The group directory answered fine, so switching type clears the line.
		wrapper.vm.newMemberType = 'group'
		await flush()
		expect(wrapper.vm.candidatesError).toBeNull()
	})

	it('offers a picker of the server groups, minus the ones already members', async () => {
		mockApi({
			owned: [
				{
					id: 'tf-1',
					folderId: 'folder-1',
					members: [
						{ id: 'm1', memberType: 'group', memberId: 'devops' },
						// A USER named like a group must not remove the group:
						// the two id spaces are separate.
						{ id: 'm2', memberType: 'user', memberId: 'support' },
					],
				},
			],
			groups: ['devops', 'support', 'admin'],
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.refresh()
		await flush()

		wrapper.vm.newMemberType = 'group'
		await flush()

		// Groups hold no key of their own, so none of them is probed.
		expect(wrapper.vm.memberCandidates).toEqual([
			{ id: 'admin', label: 'admin' },
			{ id: 'support', label: 'support' },
		])
		expect(
			wrapper.find('[data-testid="team-folder-member-select"]').exists(),
		).toBe(true)
	})

	it('searches the directory as the user types, once per burst', async () => {
		vi.useFakeTimers()
		mockApi({
			owned: [{ id: 'tf-1', folderId: 'folder-1', members: [] }],
			groups: ['devops'],
			sharees: ['carol'],
			shareable: ['carol'],
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.newMemberType = 'group'

		wrapper.vm.onCandidateSearch('de')
		wrapper.vm.onCandidateSearch('dev')
		wrapper.vm.onCandidateSearch('devo')
		vi.runAllTimers()

		// One call for three keystrokes, carrying the LAST term — both
		// directories page, so searching is how anyone past the first page is
		// reached.
		const groupSearches = axios.get.mock.calls.filter(([url]) =>
			url.includes('cloud/groups'),
		)
		expect(groupSearches).toHaveLength(1)
		expect(groupSearches[0][1].params.search).toBe('devo')

		// The user side searches its own directory, not the group one.
		wrapper.vm.newMemberType = 'user'
		wrapper.vm.onCandidateSearch('ca')
		vi.runAllTimers()
		// Back to real timers BEFORE flushing: flush() is itself a timeout, so
		// under fake ones it would never resolve.
		vi.useRealTimers()
		await flush()

		expect(
			axios.get.mock.calls.filter(([url]) => url.includes('cloud/groups')),
		).toHaveLength(1)
		const shareeSearches = axios.get.mock.calls.filter(([url]) =>
			url.includes('sharees'),
		)
		expect(shareeSearches).toHaveLength(1)
		expect(shareeSearches[0][1].params.search).toBe('ca')
	})

	it('ignores the empty term vue-select emits when an option is picked', async () => {
		vi.useFakeTimers()
		mockApi({
			owned: [{ id: 'tf-1', folderId: 'folder-1', members: [] }],
			groups: ['devops'],
		})
		const wrapper = mount(TeamFolderDialog, {
			propsData: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
		})
		wrapper.vm.newMemberType = 'group'

		wrapper.vm.onCandidateSearch('devo')
		vi.runAllTimers()
		// vue-select clears its search text on select and re-emits `search`.
		wrapper.vm.onCandidateSearch('')
		vi.runAllTimers()
		vi.useRealTimers()
		await flush()

		// Two searches would mean the list resets to page 1 under the user
		// about 300 ms after every member added.
		const groupSearches = axios.get.mock.calls.filter(([url]) =>
			url.includes('cloud/groups'),
		)
		expect(groupSearches).toHaveLength(1)
		expect(groupSearches[0][1].params.search).toBe('devo')
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
