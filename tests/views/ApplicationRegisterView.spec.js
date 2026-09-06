/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/views/ApplicationRegisterView.vue`.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#15.2
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApplicationRegisterView from '../../src/views/ApplicationRegisterView.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('ApplicationRegisterView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders the empty state when the user has no applications', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(ApplicationRegisterView)
		await flush()
		expect(wrapper.find('.cn-index-page__empty').exists()).toBe(true)
	})

	it('renders one row per registered application', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ id: 'a1', name: 'CI', status: 'pending', type: 'external' },
				{ id: 'a2', name: 'OC', status: 'active', type: 'internal' },
			],
		})
		const wrapper = mount(ApplicationRegisterView)
		await flush()
		expect(wrapper.findAll('.cn-object-row')).toHaveLength(2)
	})

	it('renders the #row-badges slot content into each row', async () => {
		// The row COUNT above passes whether or not the scoped slot renders,
		// so it never covered the slot. Vue 3 removed `$scopedSlots` (all
		// slots moved to `$slots`), and a stub still reading the old property
		// silently produced empty rows while the count assertion stayed green.
		// This asserts on the slot's actual output.
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ id: 'a1', name: 'CI', status: 'pending', type: 'external' },
				{ id: 'a2', name: 'OC', status: 'active', type: 'internal' },
			],
		})
		const wrapper = mount(ApplicationRegisterView)
		await flush()

		const badges = wrapper.findAll('.cn-object-row .cn-status-badge')
		// One status badge + one type badge per row.
		expect(badges).toHaveLength(4)
		expect(wrapper.findAll('.cn-object-row').at(0).text()).toContain('external')
		expect(wrapper.findAll('.cn-object-row').at(1).text()).toContain('internal')
	})

	// Both dialogs render through NcDialog now (they used to be in-flow
	// sections), and the test alias's NcDialog stub always renders with a
	// `data-open` attribute — so a bare `exists()` would pass vacuously.
	// The open flag is what the click actually changes.
	it('opens the dialog when the register (add) button is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(ApplicationRegisterView)
		await flush()
		const dialog = wrapper.find('[data-testid="application-register-dialog"]')
		expect(dialog.attributes('data-open')).toBe('false')
		await wrapper.find('[data-testid="cn-cta-primary"]').trigger('click')
		expect(dialog.attributes('data-open')).toBe('true')
	})

	it('opens the PrivateKeyDownloadDialog when the store has a one-time key', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(ApplicationRegisterView)
		await flush()
		const dialog = wrapper.find('[data-testid="private-key-dialog"]')
		expect(dialog.attributes('data-open')).toBe('false')
		// Simulate the registration flow having captured the key.
		const { useApplicationStore } =
			await import('../../src/store/modules/application.js')
		const store = useApplicationStore()
		store.oneTimePrivateKey =
			'-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----'
		await flush()
		expect(dialog.attributes('data-open')).toBe('true')
	})

	// The dashboard's "Register application" tile deep-links to
	// `/applications?action=register` through the router (a full page load
	// would drop the in-memory vault key). The view consumes the marker:
	// dialog open, marker stripped so a refresh cannot re-open it.
	it('opens the register dialog from the dashboard quick action and strips the marker', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const replace = vi.fn()
		const wrapper = mount(ApplicationRegisterView, {
			global: {
				mocks: {
					$route: { query: { action: 'register', view: 'cards' } },
					$router: { replace, push: vi.fn() },
				},
			},
		})
		await flush()
		expect(
			wrapper
				.find('[data-testid="application-register-dialog"]')
				.attributes('data-open'),
		).toBe('true')
		expect(replace).toHaveBeenCalledWith({ query: { view: 'cards' } })
	})

	it('ignores an action marker that is not register', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const replace = vi.fn()
		const wrapper = mount(ApplicationRegisterView, {
			global: {
				mocks: {
					$route: { query: { action: 'create' } },
					$router: { replace, push: vi.fn() },
				},
			},
		})
		await flush()
		expect(
			wrapper
				.find('[data-testid="application-register-dialog"]')
				.attributes('data-open'),
		).toBe('false')
		expect(replace).not.toHaveBeenCalled()
	})
})
