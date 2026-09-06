/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for `src/dialogs/FolderCreateDialog.vue` — the Stage-9
 * additions: the icon/color picker renders at VAULT level only and its
 * picks ride the create payload.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FolderCreateDialog from '../../src/dialogs/FolderCreateDialog.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('FolderCreateDialog (vault customization)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		// The mounted() folder fetch.
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
	})

	it('vault flow (opened at root): picker shown, NO parent select', async () => {
		// A vault is only ever created at the root (team decision): the
		// level is fixed by the opening context, so the vault dialog offers
		// no parent choice at all.
		const wrapper = mount(FolderCreateDialog)
		await flush()
		expect(wrapper.findComponent({ name: 'CnIconColorPicker' }).exists()).toBe(
			true,
		)
		expect(wrapper.findComponent({ name: 'NcSelect' }).exists()).toBe(false)
	})

	it('folder flow (opened with a parent): parent select WITHOUT the root, no picker', async () => {
		const wrapper = mount(FolderCreateDialog, {
			propsData: { parentId: 'v-1' },
		})
		await flush()
		expect(wrapper.findComponent({ name: 'CnIconColorPicker' }).exists()).toBe(
			false,
		)
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.exists()).toBe(true)
		// No root option: creating at the root is the vault flow — the two
		// flows never morph into each other mid-dialog.
		const values = (select.props('options') || []).map((o) => o.value)
		expect(values).not.toContain(null)
	})

	it('sends the picked keys with the create payload', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'v-1', name: 'Work', parentId: null },
		})
		const wrapper = mount(FolderCreateDialog)
		await flush()

		await wrapper.find('[data-testid="stub-pick-icon"]').trigger('click')
		await wrapper.find('[data-testid="stub-pick-color"]').trigger('click')
		wrapper.vm.name = 'Work'
		await wrapper.vm.submit()
		await flush()

		expect(post.mock.calls[0][1]).toEqual({
			name: 'Work',
			parentId: null,
			customIcon: 'briefcase',
			customColor: 'blue',
		})
	})

	it('never sends customization for a nested folder', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'f-1', name: 'Sub', parentId: 'v-1' },
		})
		const wrapper = mount(FolderCreateDialog, {
			propsData: { parentId: 'v-1' },
		})
		await flush()

		await wrapper.setData({ name: 'Sub' })
		await wrapper.vm.submit()
		await flush()

		expect(post.mock.calls[0][1]).toEqual({
			name: 'Sub',
			parentId: 'v-1',
		})
	})
})
