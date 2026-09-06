/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/share/DelegationManager.vue`.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#16.5
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DelegationManager from '../../src/components/share/DelegationManager.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('DelegationManager', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders the empty state when there are no delegations', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(DelegationManager, {
			propsData: { secretId: 'sec-1', canReclaim: true },
		})
		await flush()
		expect(
			wrapper.find('[data-testid="delegation-manager-empty"]').exists(),
		).toBe(true)
	})

	it('renders rows with permanent + temporary status pills', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ id: 'd1', delegatedTo: 'bob', isPermanent: false },
				{ id: 'd2', delegatedTo: 'carol', isPermanent: true },
			],
		})
		const wrapper = mount(DelegationManager, {
			propsData: { secretId: 'sec-1', canReclaim: true },
		})
		await flush()
		const statuses = wrapper.findAll('[data-testid="delegation-row-status"]')
		expect(statuses).toHaveLength(2)
		expect(statuses.at(0).text()).toContain('Temporary')
		expect(statuses.at(1).text()).toContain('Permanent')
	})

	it('disables the reclaim button when there are only permanent rows', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'd1', delegatedTo: 'bob', isPermanent: true }],
		})
		const wrapper = mount(DelegationManager, {
			propsData: { secretId: 'sec-1', canReclaim: true },
		})
		await flush()
		const button = wrapper.find('[data-testid="delegation-manager-reclaim"]')
		expect(button.attributes('disabled')).toBeDefined()
	})

	it('emits reclaimed when the reclaim button is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'd1', delegatedTo: 'bob', isPermanent: false }],
		})
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { removed: 1 } })
		const wrapper = mount(DelegationManager, {
			propsData: { secretId: 'sec-1', canReclaim: true },
		})
		await flush()
		await wrapper
			.find('[data-testid="delegation-manager-reclaim"]')
			.trigger('click')
		await flush()
		expect(wrapper.emitted('reclaimed')).toBeTruthy()
		expect(wrapper.emitted('reclaimed')[0][0]).toBe(1)
	})

	it('hides the reclaim button when canReclaim is false', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'd1', delegatedTo: 'bob', isPermanent: false }],
		})
		const wrapper = mount(DelegationManager, {
			propsData: { secretId: 'sec-1', canReclaim: false },
		})
		await flush()
		expect(
			wrapper.find('[data-testid="delegation-manager-reclaim"]').exists(),
		).toBe(false)
	})
})
