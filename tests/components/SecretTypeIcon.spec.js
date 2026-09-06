/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/SecretTypeIcon.vue`.
 *
 * SecretTypeIcon is the ONE type→glyph rendering shared by the list rows,
 * the table's Type cells and the cards (fix-brief bugs 8+9 were the card and
 * table views shipping without any type icon — the drift this component
 * exists to prevent). What these tests pin:
 *
 *  - the glyph follows the secret type's machine name through the shared
 *    map in `utils/favicon.js`
 *  - an unknown or missing type falls back to the login key glyph instead
 *    of rendering nothing (every secret must carry a recognizable glyph)
 *
 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import SecretTypeIcon from '../../src/components/SecretTypeIcon.vue'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

describe('SecretTypeIcon', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		useSecretTypeStore().types = [
			{ id: 'type-login', name: 'login', label: 'Login' },
			{ id: 'type-api', name: 'api_key', label: 'API key' },
			{ id: 'type-card', name: 'card', label: 'Payment Card' },
		]
	})

	it('renders the glyph mapped to the secret type', () => {
		const wrapper = mount(SecretTypeIcon, {
			propsData: { typeId: 'type-api' },
		})

		expect(wrapper.find('.code-tags-icon').exists()).toBe(true)
	})

	it('follows the type through the shared map, not a per-view copy', () => {
		const wrapper = mount(SecretTypeIcon, {
			propsData: { typeId: 'type-card' },
		})

		expect(wrapper.find('.credit-card-outline-icon').exists()).toBe(true)
	})

	it('falls back to the login key glyph for an unknown type', () => {
		const wrapper = mount(SecretTypeIcon, {
			propsData: { typeId: 'type-nonexistent' },
		})

		expect(wrapper.find('.key-icon').exists()).toBe(true)
	})
})
