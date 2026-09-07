/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression spec for the vault glyph in the left rail: which circle each
 * vault gets, in which state.
 *
 *   at rest, colored    the picked color + a same-hex translucent tint
 *   highlighted, colored the SAME color on an opaque main-background disc
 *   colorless (default) NO inline style — the neutral disc is CSS-only
 *
 * Every depth-0 row carries a circle, the default one included: mixing
 * discs and bare glyphs put the rows on different optical baselines and
 * started their labels at different distances from their icons.
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

	it('gives a COLORLESS vault no inline tint — its circle is CSS-only', () => {
		// "No color" has no hex to derive a tint (or an active-row variant)
		// from, so the default vault's circle is painted by the --plain
		// class instead of an inline style. Asserting the ABSENCE of the
		// inline style is what stops a future change from tinting it with
		// some arbitrary fallback color.
		const w = factory('v-plain')
		const plain = { id: 'v-plain', name: 'Plain', parentId: null, children: [] }
		expect(w.vm.isColorless(plain)).toBe(true)
		expect(w.vm.vaultGlyphStyle(plain)).toBeUndefined()
		expect(w.vm.vaultColor(plain)).toBe('currentColor')
	})

	it('marks ONLY the colorless vault as plain', () => {
		const w = factory(null)
		expect(w.vm.isColorless(VAULTS[0])).toBe(false)
		expect(w.vm.isColorless(VAULTS[1])).toBe(false)
	})

	it('renders the circle on EVERY vault, default included', () => {
		// The rail's original defect: a disc on colored vaults and a bare
		// glyph on the default one, so the two sat on different optical
		// baselines and the labels started at different distances from
		// their icons. Every depth-0 row now carries the glyph span, and
		// the default one additionally carries the neutral --plain class.
		const w = mount(NavFolderTree, {
			propsData: {
				folders: [
					...VAULTS,
					{ id: 'v-plain', name: 'Plain', parentId: null, children: [] },
				],
			},
			global: { stubs },
		})
		const discs = w.findAll('.keepiq-nav-tree__vault-glyph')
		expect(discs).toHaveLength(3)
		expect(
			discs.filter((d) => d.classes('keepiq-nav-tree__vault-glyph--plain')),
		).toHaveLength(1)
	})
})
