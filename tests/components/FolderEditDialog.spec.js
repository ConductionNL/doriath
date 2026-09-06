/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for `src/dialogs/FolderEditDialog.vue` (restyle Stage 9):
 * the vault edit dialog — rename plus the icon/color picker whose Default
 * cells send EXPLICIT nulls so a stored customization genuinely clears.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FolderEditDialog from '../../src/dialogs/FolderEditDialog.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const VAULT = {
	id: 'v-1',
	name: 'Work',
	parentId: null,
	customIcon: 'briefcase',
	customColor: 'blue',
}

describe('FolderEditDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('shows the picker for a vault, seeded from the folder', () => {
		const wrapper = mount(FolderEditDialog, {
			propsData: { folder: VAULT },
		})
		const picker = wrapper.findComponent({ name: 'CnIconColorPicker' })
		expect(picker.exists()).toBe(true)
		expect(picker.props('icon')).toBe('briefcase')
		expect(picker.props('color')).toBe('blue')
	})

	it('hides the picker for a nested folder (customization is vault-scoped)', () => {
		const wrapper = mount(FolderEditDialog, {
			propsData: {
				folder: { id: 'f-1', name: 'Sub', parentId: 'v-1' },
			},
		})
		expect(wrapper.findComponent({ name: 'CnIconColorPicker' }).exists()).toBe(
			false,
		)
	})

	it('saves picked keys and the changed name in one update', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({
			data: { ...VAULT, name: 'Renamed' },
		})
		const wrapper = mount(FolderEditDialog, {
			propsData: { folder: { ...VAULT, customIcon: null, customColor: null } },
		})

		await wrapper.find('[data-testid="stub-pick-icon"]').trigger('click')
		await wrapper.find('[data-testid="stub-pick-color"]').trigger('click')
		wrapper.vm.name = 'Renamed'
		await wrapper.find('[data-testid="folder-edit-save"]').trigger('click')
		await flush()

		expect(put).toHaveBeenCalledTimes(1)
		expect(put.mock.calls[0][1]).toEqual({
			name: 'Renamed',
			customIcon: 'briefcase',
			customColor: 'blue',
		})
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('sends EXPLICIT nulls on reset so stored values clear server-side', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({
			data: { ...VAULT, customIcon: null, customColor: null },
		})
		const wrapper = mount(FolderEditDialog, {
			propsData: { folder: VAULT },
		})

		await wrapper.find('[data-testid="stub-clear-style"]').trigger('click')
		await wrapper.find('[data-testid="folder-edit-save"]').trigger('click')
		await flush()

		// Unchanged name stays OUT of the body (no accidental rename);
		// the customization keys are PRESENT with null (clear semantics).
		expect(put.mock.calls[0][1]).toEqual({
			customIcon: null,
			customColor: null,
		})
	})

	it('surfaces a failed update inline and stays open', async () => {
		vi.spyOn(axios, 'put').mockRejectedValue({
			response: { data: { message: 'Duplicate name' } },
		})
		const wrapper = mount(FolderEditDialog, {
			propsData: { folder: VAULT },
		})

		wrapper.vm.name = 'Taken'
		await wrapper.find('[data-testid="folder-edit-save"]').trigger('click')
		await flush()

		expect(wrapper.vm.error).toBe('Duplicate name')
		expect(wrapper.emitted('saved')).toBeFalsy()
		expect(wrapper.emitted('close')).toBeFalsy()
	})
})
