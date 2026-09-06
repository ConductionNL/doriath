/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Diagnostic + regression spec for the vault glyph's highlight behavior
 * (restyle Stage 9): the HIGHLIGHTED vault renders a plain currentColor
 * glyph with no tint circle; at rest the picked color + same-hex tint show.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NavFolderTree from '../../src/components/KeepiqAppNav/NavFolderTree.vue'

const stubs = {
	NcAppNavigationItem: {
		props: ['name', 'to', 'active', 'allowCollapse', 'open'],
		template:
			'<li :data-active="active"><slot name="icon" /><slot name="actions" /><slot /></li>',
	},
	NcActionButton: { template: '<button><slot /></button>' },
	NcActionSeparator: { template: '<hr />' },
}

const VAULTS = [
	{
		id: 'v-all',
		name: 'All',
		parentId: null,
		customIcon: 'briefcase',
		customColor: 'blue',
		children: [{ id: 'f-sub', name: 'Test2', parentId: 'v-all', children: [] }],
	},
	{
		id: 'v-keys',
		name: 'Keys',
		parentId: null,
		customIcon: 'star',
		customColor: 'red',
		children: [],
	},
]

function factory(highlightId) {
	return mount(NavFolderTree, {
		propsData: { folders: VAULTS, highlightId },
		global: { stubs },
	})
}

describe('NavFolderTree vault glyph highlight', () => {
	it('renders the HIGHLIGHTED vault on the OPAQUE theme disc, keeping its color', () => {
		// Team decision, settled after trying both alternatives: the
		// selected row shows the vault's COLORED glyph on a disc in the
		// theme's main background (white in light, dark in dark) — the
		// rest-state pairing at unchanged contrast, so the identity
		// survives selection.
		const w = factory('v-all')
		const vm = w.vm
		const all = VAULTS[0]
		expect(vm.isHighlighted(all)).toBe(true)
		expect(vm.vaultColor(all)).toBe('#0064a3') // stays colored
		expect(vm.vaultGlyphStyle(all)).toEqual({
			backgroundColor: 'var(--color-main-background)',
		})
	})

	it('renders a highlighted vault WITH CHILDREN identically to a leaf vault', () => {
		const withChildren = factory('v-all')
		const leaf = factory('v-keys')
		expect(withChildren.vm.vaultGlyphStyle(VAULTS[0])).toEqual(
			leaf.vm.vaultGlyphStyle(VAULTS[1]),
		)
	})

	it('renders vaults AT REST with their color and same-hex tint', () => {
		const w = factory('v-all')
		const keys = VAULTS[1]
		expect(w.vm.isHighlighted(keys)).toBe(false)
		// Stub resolvers: red light-variant hex + derived tint.
		expect(w.vm.vaultColor(keys)).toBe('#c92020')
		expect(w.vm.vaultGlyphStyle(keys)).toBeTruthy()
		expect(w.vm.vaultGlyphStyle(keys).backgroundColor).not.toBe(
			'var(--color-main-background)',
		)
	})

	it('renders a COLORLESS vault with no circle in every state', () => {
		const w = factory('v-plain')
		const plain = { id: 'v-plain', name: 'Plain', parentId: null, children: [] }
		expect(w.vm.vaultGlyphStyle(plain)).toBeUndefined()
		expect(w.vm.vaultColor(plain)).toBe('currentColor')
	})
})
