/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/views/DashboardSettingsView.vue`.
 *
 * @spec openspec/changes/implement-dashboard-settings/tasks.md#5.3
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import DashboardSettingsView from '../../src/views/DashboardSettingsView.vue'

describe('DashboardSettingsView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('hydrates the form from the server on mount', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				settings: {
					default_view: 'grid',
					sort_field: 'created_at',
					sort_direction: 'desc',
					show_kpi_widget: '0',
					show_recent_widget: '1',
					show_migration_banner: '0',
				},
			},
		})

		const wrapper = mount(DashboardSettingsView)
		await flushPromises()

		expect(
			wrapper.find('[data-testid="default-view-select"]').element.value,
		).toBe('grid')
		expect(wrapper.find('[data-testid="sort-field-select"]').element.value).toBe(
			'created_at',
		)
		expect(
			wrapper.find('[data-testid="sort-direction-select"]').element.value,
		).toBe('desc')
		expect(wrapper.find('[data-testid="kpi-toggle"]').element.checked).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="recent-toggle"]').element.checked).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="migration-toggle"]').element.checked,
		).toBe(false)
	})

	it('falls back to widget defaults when the server has no preferences stored', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { settings: {} } })

		const wrapper = mount(DashboardSettingsView)
		await flushPromises()

		// Empty strings map to "system default".
		expect(
			wrapper.find('[data-testid="default-view-select"]').element.value,
		).toBe('')
		// Widget toggles default to true.
		expect(wrapper.find('[data-testid="kpi-toggle"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="recent-toggle"]').element.checked).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="migration-toggle"]').element.checked,
		).toBe(true)
	})

	it('PUTs the (cleaned) form on submit', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { settings: {} } })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({
			data: { settings: { default_view: 'list' } },
		})

		const wrapper = mount(DashboardSettingsView)
		await flushPromises()

		await wrapper.find('[data-testid="default-view-select"]').setValue('list')
		await wrapper.find('[data-testid="kpi-toggle"]').setChecked(false)

		await wrapper
			.find('[data-testid="dashboard-settings-form"]')
			.trigger('submit')
		await flushPromises()

		expect(put).toHaveBeenCalledOnce()
		const [url, body] = put.mock.calls[0]
		expect(url).toBe('/apps/keepiq/api/v1/dashboard-settings')
		// Empty selects are dropped — they ask for the system default.
		expect(body.settings).toEqual({
			default_view: 'list',
			show_kpi_widget: '0',
			show_recent_widget: '1',
			show_migration_banner: '1',
		})
		// Empty selects must NOT be in the payload.
		expect(body.settings).not.toHaveProperty('sort_field')
		expect(body.settings).not.toHaveProperty('sort_direction')
	})

	it('clears the per-user overrides when reset is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { settings: { default_view: 'grid' } },
		})
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })

		const wrapper = mount(DashboardSettingsView)
		await flushPromises()

		await wrapper.find('[data-testid="reset-settings"]').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/dashboard-settings')
		expect(
			wrapper.find('[data-testid="default-view-select"]').element.value,
		).toBe('')
	})

	it('shows the error message when the API fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('Bad gateway'))

		const wrapper = mount(DashboardSettingsView)

		// Give the rejection time to settle without bubbling out of created().
		await flushPromises()
		await flushPromises()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Bad gateway')
	})
})
