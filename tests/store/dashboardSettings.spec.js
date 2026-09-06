/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the `useDashboardSettingsStore` Pinia store
 * (`src/store/modules/dashboardSettings.js`).
 *
 * @spec openspec/changes/implement-dashboard-settings/tasks.md#6.1
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	ALLOWED_DASHBOARD_KEYS,
	useDashboardSettingsStore,
} from '../../src/store/modules/dashboardSettings.js'

describe('useDashboardSettingsStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchAll', () => {
		it('hydrates the settings map from the API', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: {
					settings: { default_view: 'grid', sort_field: 'name' },
				},
			})
			const store = useDashboardSettingsStore()
			await store.fetchAll()

			expect(store.settings.default_view).toBe('grid')
			expect(store.settings.sort_field).toBe('name')
			expect(store.hasSettings).toBe(true)
		})

		it('accepts a flat object payload', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: { default_view: 'list' },
			})
			const store = useDashboardSettingsStore()
			await store.fetchAll()
			expect(store.settings.default_view).toBe('list')
		})

		it('stores the error message when the request throws', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('500'))
			const store = useDashboardSettingsStore()
			await expect(store.fetchAll()).rejects.toThrow('500')
			expect(store.error).toBe('500')
			expect(store.loading).toBe(false)
		})
	})

	describe('get', () => {
		it('returns the stored value when set', () => {
			const store = useDashboardSettingsStore()
			store.settings = { default_view: 'grid' }
			expect(store.get('default_view')).toBe('grid')
		})

		it('returns the fallback when the key is missing', () => {
			const store = useDashboardSettingsStore()
			expect(store.get('default_view', 'list')).toBe('list')
			expect(store.get('missing')).toBeNull()
		})
	})

	describe('setMany', () => {
		it('filters out keys not in the allow-list', async () => {
			const put = vi.spyOn(axios, 'put').mockResolvedValue({
				data: { settings: { default_view: 'grid' } },
			})
			const store = useDashboardSettingsStore()

			await store.setMany({
				default_view: 'grid',
				malicious_key: 'never-saved',
				sort_field: 'name',
			})

			const [url, body] = put.mock.calls[0]
			expect(url).toBe('/apps/keepiq/api/v1/dashboard-settings')
			expect(body.settings).toEqual({
				default_view: 'grid',
				sort_field: 'name',
			})
			expect(body.settings).not.toHaveProperty('malicious_key')
		})

		it('merges the response into the in-memory map', async () => {
			vi.spyOn(axios, 'put').mockResolvedValue({
				data: { settings: { default_view: 'grid' } },
			})
			const store = useDashboardSettingsStore()
			store.settings = { sort_field: 'name' }

			await store.setMany({ default_view: 'grid' })

			expect(store.settings).toEqual({
				sort_field: 'name',
				default_view: 'grid',
			})
		})

		it('short-circuits when the filtered payload is empty', async () => {
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
			const store = useDashboardSettingsStore()

			await store.setMany({ rogue_key: 'x' })
			expect(put).not.toHaveBeenCalled()
		})
	})

	describe('set', () => {
		it('returns null for an unknown key without hitting the API', async () => {
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
			const store = useDashboardSettingsStore()
			const result = await store.set('rogue', 'x')
			expect(result).toBeNull()
			expect(put).not.toHaveBeenCalled()
		})

		it('persists an allow-listed key', async () => {
			vi.spyOn(axios, 'put').mockResolvedValue({
				data: { settings: { sort_direction: 'asc' } },
			})
			const store = useDashboardSettingsStore()
			const result = await store.set('sort_direction', 'asc')
			expect(result).toBe('asc')
			expect(store.settings.sort_direction).toBe('asc')
		})
	})

	describe('reset', () => {
		it('calls DELETE and clears the in-memory map', async () => {
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useDashboardSettingsStore()
			store.settings = { default_view: 'grid' }

			await store.reset()

			expect(del).toHaveBeenCalledWith(
				'/apps/keepiq/api/v1/dashboard-settings',
			)
			expect(store.settings).toEqual({})
		})
	})

	describe('ALLOWED_DASHBOARD_KEYS', () => {
		it('is frozen so callers cannot mutate the allow-list at runtime', () => {
			expect(Object.isFrozen(ALLOWED_DASHBOARD_KEYS)).toBe(true)
		})

		it('includes the canonical preference keys', () => {
			expect(ALLOWED_DASHBOARD_KEYS).toContain('default_view')
			expect(ALLOWED_DASHBOARD_KEYS).toContain('sort_field')
			expect(ALLOWED_DASHBOARD_KEYS).toContain('show_kpi_widget')
		})
	})
})
