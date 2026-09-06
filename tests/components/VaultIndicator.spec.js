/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/VaultIndicator.vue` — the one rendering
 * of "which vault does this live in" outside the vault's own page
 * (2026-09-03, Proton pattern). What these tests pin:
 *
 *  - `dot` carries the vault name for EVERYONE: tooltip for pointer users,
 *    visually hidden text for screen readers (an unlabeled colored circle
 *    would be color-alone information, WCAG 1.4.1)
 *  - `tag` shows the vault name visibly (the detail sidebar's vault tag)
 *  - the vault's Stage 9 icon renders; unset/unknown icons fall back to the
 *    safe glyph rather than rendering nothing
 *  - no vault, no render — callers pass their resolution result straight in
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import VaultIndicator from '../../src/components/VaultIndicator.vue'

const VAULT = { id: 'v-1', name: 'Keepiq', customIcon: null, customColor: null }

describe('VaultIndicator', () => {
	it('renders nothing without a vault', () => {
		const wrapper = mount(VaultIndicator, { propsData: { vault: null } })
		expect(wrapper.find('.vault-indicator').exists()).toBe(false)
	})

	it('dot: names the vault for pointer AND screen-reader users', () => {
		const wrapper = mount(VaultIndicator, {
			propsData: { vault: VAULT, variant: 'dot' },
		})

		const dot = wrapper.find('.vault-indicator--dot')
		expect(dot.attributes('title')).toBe('Keepiq')
		expect(dot.find('.vault-indicator__sr').text()).toBe('Keepiq')
		expect(dot.find('.vault-indicator__name').exists()).toBe(false)
	})

	it('tag: shows the vault name visibly', () => {
		const wrapper = mount(VaultIndicator, {
			propsData: { vault: VAULT, variant: 'tag' },
		})

		const tag = wrapper.find('.vault-indicator--tag')
		expect(tag.find('.vault-indicator__name').text()).toBe('Keepiq')
		expect(tag.attributes('title')).toBeUndefined()
	})

	it('falls back to the safe glyph for unset and unknown icons', () => {
		const unset = mount(VaultIndicator, { propsData: { vault: VAULT } })
		expect(unset.find('.safe-icon').exists()).toBe(true)

		const unknown = mount(VaultIndicator, {
			propsData: { vault: { ...VAULT, customIcon: 'not-a-real-key' } },
		})
		expect(unknown.find('.safe-icon').exists()).toBe(true)
	})

	// The library is stubbed in vitest (tests/vitest/stubs): a known key
	// resolves to the stub icon component, which is exactly the contract —
	// the picked icon renders instead of the safe fallback.
	it('renders the vault’s picked Stage 9 icon when set', () => {
		const wrapper = mount(VaultIndicator, {
			propsData: { vault: { ...VAULT, customIcon: 'briefcase' } },
		})
		expect(wrapper.find('[data-stub="folder-icon"]').exists()).toBe(true)
		expect(wrapper.find('.safe-icon').exists()).toBe(false)
	})
})
