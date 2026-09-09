/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `useGroupStore` (`src/store/modules/group.js`).
 *
 * Groups are the SERVER's data, not keepiq's, so the only thing this store can
 * get wrong is how it asks — and every one of those mistakes answers with
 * something other than a group list:
 *
 *   - no `OCS-APIRequest` header  -> 401, whatever the session says
 *   - no `format=json`            -> XML, and `data.ocs` undefined
 *   - reading `data.groups`       -> undefined, because OCS wraps its payload
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { GROUP_PAGE_SIZE, useGroupStore } from '../../src/store/modules/group.js'

describe('useGroupStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('asks the provisioning API the way OCS requires, and unwraps the envelope', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				ocs: { meta: { status: 'ok' }, data: { groups: ['ops', 'dev'] } },
			},
		})

		const store = useGroupStore()
		// One option shape with the user candidates, which do have display
		// names; a group has none, so its label is its id.
		await expect(store.fetchGroups('e')).resolves.toEqual([
			{ id: 'dev', label: 'dev' },
			{ id: 'ops', label: 'ops' },
		])

		const [url, config] = get.mock.calls[0]
		expect(url).toContain('cloud/groups')
		expect(config.headers['OCS-APIRequest']).toBe('true')
		expect(config.params).toMatchObject({
			format: 'json',
			search: 'e',
			limit: GROUP_PAGE_SIZE,
		})

		// Sorted, so the picker's order does not depend on the server's.
		expect(store.groups.map((option) => option.id)).toEqual(['dev', 'ops'])
		expect(store.loading).toBe(false)
		expect(store.candidatesError).toBeNull()
	})

	it('treats a payload without a group list as no groups', async () => {
		// An XML answer, an error envelope, or a future shape change: none of
		// them may throw inside the picker's best-effort lookup.
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { ocs: { data: {} } } })

		await expect(useGroupStore().fetchGroups()).resolves.toEqual([])
	})

	it('clears the loading flag and records why the request failed', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('network'))

		const store = useGroupStore()
		await expect(store.fetchGroups()).rejects.toThrow('network')

		// The caller swallows this error; a stuck spinner would be the only
		// trace left of it. The recorded reason is the other trace: without it
		// a 401 and "no groups match" reach the picker identically.
		expect(store.loading).toBe(false)
		expect(store.candidatesError).toBe('network')
	})

	it('lets the last search typed win, not the last to answer', async () => {
		// Two searches in flight: "dev" is slow, "devops" is fast. Whoever
		// answers last would otherwise own the picker.
		const slow = { data: { ocs: { data: { groups: ['dev', 'devops'] } } } }
		const fast = { data: { ocs: { data: { groups: ['devops'] } } } }
		let releaseSlow
		vi.spyOn(axios, 'get')
			.mockImplementationOnce(
				() =>
					new Promise((resolve) => {
						releaseSlow = () => resolve(slow)
					}),
			)
			.mockResolvedValueOnce(fast)

		const store = useGroupStore()
		const first = store.fetchGroups('dev')
		await store.fetchGroups('devops')
		expect(store.groups.map((option) => option.id)).toEqual(['devops'])

		releaseSlow()
		// The superseded call still reports its own answer to its own caller,
		// but does not write it — nor un-set a flag the newer search owns.
		await expect(first).resolves.toEqual([
			{ id: 'dev', label: 'dev' },
			{ id: 'devops', label: 'devops' },
		])
		expect(store.groups.map((option) => option.id)).toEqual(['devops'])
		expect(store.loading).toBe(false)
	})
})
