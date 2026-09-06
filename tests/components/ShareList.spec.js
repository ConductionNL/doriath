/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/share/ShareList.vue`.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#16.4
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShareList from '../../src/components/share/ShareList.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('ShareList', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders the empty state when the secret has no shares', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(ShareList, { propsData: { secretId: 'sec-1' } })
		await flush()
		expect(wrapper.find('[data-testid="share-list-empty"]').exists()).toBe(true)
	})

	it('renders one row per share', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ id: 's1', target_user_id: 'alice' },
				{ id: 's2', target_user_id: 'bob', group_share_id: 'g1' },
			],
		})
		const wrapper = mount(ShareList, {
			propsData: { secretId: 'sec-1', canRevoke: true },
		})
		await flush()
		expect(wrapper.findAll('[data-testid="share-row"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-testid="share-row-revoke"]')).toHaveLength(2)
	})

	it('hides the revoke button when canRevoke is false', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 's1', target_user_id: 'alice' }],
		})
		const wrapper = mount(ShareList, {
			propsData: { secretId: 'sec-1', canRevoke: false },
		})
		await flush()
		expect(wrapper.find('[data-testid="share-row-revoke"]').exists()).toBe(false)
	})

	it('dispatches the revoke action when the revoke button is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 's1', target_user_id: 'alice' }],
		})
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = mount(ShareList, {
			propsData: { secretId: 'sec-1', canRevoke: true },
		})
		await flush()
		await wrapper.find('[data-testid="share-row-revoke"]').trigger('click')
		// `flush()` is a SINGLE macrotask tick, and the submit path awaits an
		// async chain before it posts. One tick is not guaranteed to drain it,
		// so this asserted on a call that had not happened yet and failed about
		// once in twenty full-suite runs while passing every time in isolation.
		// waitFor retries until the call lands, and still fails if it never does.
		await flush()
		await vi.waitFor(() => expect(del).toHaveBeenCalled())
	})
})
