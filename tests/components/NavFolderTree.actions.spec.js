/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The vault "⋮" menu in the left rail.
 *
 * Creating a folder belongs HERE and only here: a folder is only ever made
 * inside a vault (FolderCreateDialog fixes the level from `parentId` at open
 * time), so the menu of the vault you are pointing at is the one surface that
 * already knows the parent. Nested folder rows carry no menu at all, which is
 * what keeps a folder from being offered a folder as a parent from the rail.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NavFolderTree from '../../src/components/KeepiqAppNav/NavFolderTree.vue'

const stubs = {
	NcAppNavigationItem: {
		props: ['name', 'to', 'active', 'allowCollapse', 'open'],
		template:
			'<li :data-name="name"><slot name="icon" /><slot name="actions" /><slot /></li>',
	},
	NcActionButton: {
		template:
			'<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcActionSeparator: { template: '<hr />' },
}

const VAULTS = [
	{
		id: 'v-1',
		name: 'Suppliers',
		parentId: null,
		children: [{ id: 'f-1', name: 'Invoices', parentId: 'v-1', children: [] }],
	},
]

describe('NavFolderTree vault actions', () => {
	it('offers "New folder" on a vault and emits the vault it was opened on', async () => {
		const wrapper = mount(NavFolderTree, {
			propsData: { folders: VAULTS },
			global: { stubs },
		})

		const button = wrapper.find('[data-testid="nav-folder-new-v-1"]')
		expect(button.exists()).toBe(true)

		await button.trigger('click')

		// The node itself, not just its id: the host sets `parentId` from it,
		// and that value is what decides vault-flow vs folder-flow.
		expect(wrapper.emitted('newFolder')).toBeTruthy()
		expect(wrapper.emitted('newFolder')[0][0].id).toBe('v-1')
	})

	it('offers no create action on a nested folder row', () => {
		const wrapper = mount(NavFolderTree, {
			propsData: { folders: VAULTS },
			global: { stubs },
		})

		// The child row renders (it is inside the display cap) but has no menu:
		// only depth 0 does.
		expect(wrapper.find('[data-name="Invoices"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="nav-folder-new-f-1"]').exists()).toBe(
			false,
		)
	})
})
