/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shared destination picker for the move dialogs.
 *
 * Three dialogs had each grown their own copy of this control, so the same
 * choice looked different depending on how you reached it. Extracting it is
 * what keeps them identical; these tests pin the part that legitimately
 * differs — the candidate set — plus the decisions that are easy to undo by
 * accident:
 *
 *  - Options are TREE-ordered, a vault followed by its own folders, because
 *    the flat store order listed every vault and then every folder and read
 *    as two unrelated groups.
 *  - There is no vault-root destination. A secret always lives in a vault;
 *    a rootless secret has nowhere to be displayed.
 *  - The list TELEPORTS, so it floats over the dialog rather than being
 *    clipped inside its scroll box.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DestinationSelect from '../../src/components/DestinationSelect.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'

// Deliberately NOT in tree order, and with siblings out of alphabetical
// order, so the ordering assertions below are about the component and not
// about the fixture happening to be sorted already.
const FOLDERS = [
	{ id: 'f2', name: 'Staging', parentId: 'f1' },
	{
		id: 'v2',
		name: 'Work',
		parentId: null,
		customIcon: null,
		customColor: null,
	},
	{ id: 'f1', name: 'Servers', parentId: 'v1' },
	{
		id: 'v1',
		name: 'Personal',
		parentId: null,
		customIcon: 'key',
		customColor: 'blue',
	},
	{ id: 'f3', name: 'Archive', parentId: 'v1' },
]

/**
 * Mount the picker with the select stubbed so its resolved options are
 * inspectable as a prop rather than through vue-select's internals.
 *
 * @param {object} propsData Props to pass.
 * @return {object} The wrapper.
 */
function mountPicker(propsData = {}) {
	return mount(DestinationSelect, {
		propsData: { label: 'Destination', ...propsData },
		global: {
			stubs: {
				NcSelect: {
					// The stub needs its own name: findComponent({ name }) matches the
					// stub, not the component it replaced.
					name: 'NcSelect',
					props: [
						'modelValue',
						'options',
						'reduce',
						'inputLabel',
						'disabled',
						'appendToBody',
						'clearable',
					],
					template: '<div class="stub-select" />',
				},
			},
		},
	})
}

/**
 * The options the picker handed to the select.
 *
 * @param {object} wrapper Mounted wrapper.
 * @return {Array<object>} Option objects.
 */
function options(wrapper) {
	return wrapper.findComponent({ name: 'NcSelect' }).props('options')
}

describe('DestinationSelect', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const store = useFolderStore()
		store.folders = [...FOLDERS]
		store.fetchFolders = vi.fn().mockResolvedValue()
	})

	describe('mode="folders" — where a secret can go', () => {
		it('lists each vault followed by its own folders, not vaults then folders', () => {
			const rows = options(mountPicker({ mode: 'folders' })).map(
				(o) => `${o.depth}:${o.label}`,
			)

			expect(rows).toEqual([
				'0:Personal',
				'1:Archive',
				'1:Servers',
				'2:Staging',
				'0:Work',
			])
		})

		it('offers no vault root — a secret always lives in a vault', () => {
			const values = options(mountPicker({ mode: 'folders' })).map(
				(o) => o.value,
			)

			expect(values).not.toContain(null)
			expect(values.every((v) => typeof v === 'string')).toBe(true)
		})

		it('offers the vaults themselves, so a secret can sit directly in one', () => {
			const values = options(mountPicker({ mode: 'folders' })).map(
				(o) => o.value,
			)

			expect(values).toContain('v1')
			expect(values).toContain('v2')
		})

		// The indentation carries the hierarchy, so the label does not have to.
		it('labels rows with plain names and indents by depth', () => {
			const wrapper = mountPicker({ mode: 'folders' })
			const staging = options(wrapper).find((o) => o.value === 'f2')

			expect(staging.label).toBe('Staging')
			expect(staging.label).not.toContain('/')
			expect(wrapper.vm.indentStyle(staging).paddingInlineStart).toBe('32px')
			expect(wrapper.vm.indentStyle({ depth: 0 }).paddingInlineStart).toBe(
				'0px',
			)
		})
	})

	describe('mode="vaults" — where a vault\'s contents can go', () => {
		it('offers only vaults, never folders', () => {
			const values = options(mountPicker({ mode: 'vaults' })).map(
				(o) => o.value,
			)

			expect(values).toEqual(['v1', 'v2'])
		})

		it('does not offer the source vault as its own destination', () => {
			const values = options(
				mountPicker({ mode: 'vaults', excludeId: 'v1' }),
			).map((o) => o.value)

			expect(values).toEqual(['v2'])
		})
	})

	describe('excludeId drops the whole subtree', () => {
		// A folder cannot be moved inside itself, and its children cannot
		// receive it — so excluding an entry must exclude its descendants.
		it('removes a folder and everything under it', () => {
			const values = options(
				mountPicker({ mode: 'folders', excludeId: 'f1' }),
			).map((o) => o.value)

			expect(values).not.toContain('f1')
			expect(values).not.toContain('f2')
			expect(values).toContain('f3')
		})

		it('removes a vault and every folder inside it', () => {
			const values = options(
				mountPicker({ mode: 'folders', excludeId: 'v1' }),
			).map((o) => o.value)

			expect(values).toEqual(['v2'])
		})
	})

	describe('identity and behaviour shared by every caller', () => {
		it("carries each entry's own icon and colour through to the option", () => {
			const personal = options(mountPicker({ mode: 'vaults' })).find(
				(o) => o.value === 'v1',
			)

			expect(personal.customIcon).toBe('key')
			expect(personal.customColor).toBe('blue')
		})

		// By DEPTH, not by picker mode: the folders list shows vaults as vaults.
		it('falls back to a vault glyph at depth 0 and a folder glyph below', () => {
			const wrapper = mountPicker({ mode: 'folders' })
			const vault = wrapper.vm.glyphIcon({ depth: 0, customIcon: null })
			const folder = wrapper.vm.glyphIcon({ depth: 1, customIcon: null })

			expect(vault).toBeTruthy()
			expect(folder).toBeTruthy()
			expect(vault).not.toBe(folder)
		})

		it('never hands the glyph a null fill colour', () => {
			// An explicit null fill-color strips the SVG fill and paints it black.
			const wrapper = mountPicker({ mode: 'vaults' })

			expect(typeof wrapper.vm.glyphColor({ customColor: null })).toBe(
				'string',
			)
		})

		// It floats over the dialog instead of being trapped in its scroll box.
		// Forcing it inline is what clipped it to a sliver.
		it('leaves the option list teleporting', () => {
			expect(
				mountPicker()
					.findComponent({ name: 'NcSelect' })
					.props('appendToBody'),
			).toBeUndefined()
		})

		it('is not clearable — a move needs a destination', () => {
			expect(
				mountPicker().findComponent({ name: 'NcSelect' }).props('clearable'),
			).toBe(false)
		})

		it("passes the host's label and disabled state through", () => {
			const select = mountPicker({
				label: 'Target vault',
				disabled: true,
			}).findComponent({ name: 'NcSelect' })

			expect(select.props('inputLabel')).toBe('Target vault')
			expect(select.props('disabled')).toBe(true)
		})
	})

	describe('store hydration', () => {
		it('loads folders when the host opened it before the nav had any', () => {
			const store = useFolderStore()
			store.folders = []
			mountPicker()

			expect(store.fetchFolders).toHaveBeenCalled()
		})

		it('does not refetch when the store is already populated', () => {
			mountPicker()

			expect(useFolderStore().fetchFolders).not.toHaveBeenCalled()
		})

		// A picker that cannot refresh should offer what the store holds, not
		// reject into its host's mount and take the dialog down with it.
		it('survives a failed hydration', async () => {
			const store = useFolderStore()
			store.folders = []
			store.fetchFolders = vi.fn().mockRejectedValue(new Error('offline'))

			const wrapper = mountPicker()
			await wrapper.vm.$nextTick()

			expect(wrapper.find('.stub-select').exists()).toBe(true)
		})
	})
})
