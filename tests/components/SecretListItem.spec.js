/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/SecretListItem.vue`.
 *
 * SecretListItem is the row component on SecretList. It MUST:
 *  - render the secret's name and url
 *  - emit `open` with the secret id when the row is clicked (so the
 *    parent list can navigate to /secrets/{id})
 *  - render the type-icon fallback when the URL has no favicon AND no
 *    favicon service is configured (the default privacy posture)
 *  - render a "Locked — suite revoked" badge instead of the copy button
 *    when secret.blocked is true (revoked-suite handling per ADR-007)
 *  - NOT bubble the inner CopyButton click as an `open` event (stop
 *    propagation on the actions slot — clicking copy must not navigate)
 *  - on copy, lazily decrypt by calling useSecretStore().fetchSecret
 *
 * Keyboard accessibility (WCAG 2.1 AA SC 2.1.1 / 4.1.2, ADR-010):
 *  - the row exposes role="button", tabindex="0" and an aria-label so a
 *    keyboard-only user can Tab to it and knows what it does
 *  - Enter and Space on the focused row emit `open` (matching click)
 *  - keyboard activation of the inner copy control does NOT bubble as `open`
 *
 * @spec openspec/changes/implement-secrets/tasks.md#7.3
 * @spec openspec/changes/implement-secrets/tasks.md#13.3
 * @spec openspec/changes/keyboard-accessible-secret-rows/tasks.md#2.3
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretListItem from '../../src/components/SecretListItem.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

describe('SecretListItem', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		// Seed the type store so the iconComponent computed has data.
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'type-login', name: 'login', label: 'Login' },
			{ id: 'type-api', name: 'api_key', label: 'API key' },
		]
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: vi.fn().mockResolvedValue() },
			configurable: true,
		})
		document.execCommand = vi.fn().mockReturnValue(true)
	})

	it('renders the secret name and url', () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: {
					id: 's-1',
					name: 'GitHub PAT',
					url: 'https://github.com',
					typeId: 'type-api',
				},
			},
		})

		expect(wrapper.text()).toContain('GitHub PAT')
		expect(wrapper.text()).toContain('https://github.com')
	})

	// Cross-vault hosts (the All-secrets root) pass `vault` so the row can
	// say where its secret lives (2026-09-03, Proton pattern); without the
	// prop — inside a vault — no dot renders.
	it('renders the vault dot only when a vault is passed', () => {
		const secret = { id: 's-7', name: 'AWS', url: null, typeId: 'type-login' }

		const bare = mount(SecretListItem, { propsData: { secret } })
		expect(bare.find('[data-testid="secret-vault-dot-s-7"]').exists()).toBe(
			false,
		)

		const withVault = mount(SecretListItem, {
			propsData: {
				secret,
				vault: { id: 'v-1', name: 'Keepiq' },
			},
		})
		const dot = withVault.find('[data-testid="secret-vault-dot-s-7"]')
		expect(dot.exists()).toBe(true)
		expect(dot.attributes('title')).toBe('Keepiq')
	})

	it('emits `open` with the secret id on row click', async () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-42', name: 'AWS', url: null, typeId: 'type-login' },
			},
		})

		await wrapper.find('.secret-list-item').trigger('click')

		expect(wrapper.emitted('open')).toBeTruthy()
		expect(wrapper.emitted('open')[0]).toEqual(['s-42'])
	})

	it('renders the type-icon fallback when favicons are disabled (default)', () => {
		// faviconServiceUrl loadState returns '' (default) so resolveFaviconUrl
		// returns null and the <img> branch is skipped.
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: {
					id: 's-1',
					name: 'X',
					url: 'https://github.com',
					typeId: 'type-api',
				},
			},
		})

		expect(wrapper.find('img').exists()).toBe(false)
		// The fallback icon component renders an SVG via vue-material-design-icons.
		expect(wrapper.find('.secret-list-item__icon').exists()).toBe(true)
	})

	it('renders the locked badge instead of CopyButton when secret.blocked', () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: {
					id: 's-1',
					name: 'Old API',
					url: 'https://old.example',
					typeId: 'type-api',
					blocked: true,
					blockedReason: 'suite-revoked',
				},
			},
		})

		expect(wrapper.text()).toContain('Locked — suite revoked')
		expect(wrapper.find('.secret-list-item__blocked').exists()).toBe(true)
		expect(wrapper.classes()).toContain('secret-list-item--blocked')
		// The copy actions area MUST NOT render when blocked.
		expect(wrapper.find('.secret-list-item__actions').exists()).toBe(false)
	})

	it('warns on the row when the secret is possibly compromised', () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: {
					id: 's-1',
					name: 'Router admin',
					url: 'https://router.local',
					typeId: 'type-login',
					possiblyCompromisedAt: '2026-08-11T12:00:00+00:00',
				},
			},
		})

		expect(
			wrapper.find('[data-testid="secret-possibly-compromised"]').exists(),
		).toBe(true)
		expect(wrapper.text()).toContain('Assume this value was exposed')
		expect(wrapper.text()).toContain('change it at its source')
	})

	it('shows no compromise warning when the flag is absent', () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: {
					id: 's-1',
					name: 'Router admin',
					url: 'https://router.local',
					typeId: 'type-login',
					possiblyCompromisedAt: null,
				},
			},
		})

		expect(
			wrapper.find('[data-testid="secret-possibly-compromised"]').exists(),
		).toBe(false)
	})

	it('warns on a BLOCKED row too, where the flag matters most', () => {
		// A secret that failed migration returns no ciphertext, so the health
		// pass can never see it. The row-level flag is the only signal left.
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: {
					id: 's-1',
					name: 'Unreadable',
					typeId: 'type-api',
					blocked: true,
					unrecoverable: true,
					possiblyCompromisedAt: '2026-08-11T12:00:00+00:00',
				},
			},
		})

		expect(
			wrapper.find('[data-testid="secret-possibly-compromised"]').exists(),
		).toBe(true)
		// And the lock reason must not blame a revoked suite.
		expect(wrapper.text()).toContain('Could not be migrated to your new key')
		expect(wrapper.text()).not.toContain('suite revoked')
	})

	it('clicking the copy button does NOT bubble as `open` (stop.propagation)', async () => {
		const secretStore = useSecretStore()
		secretStore.fetchSecret = vi.fn().mockResolvedValue({
			id: 's-1',
			name: 'X',
			key: 'plaintext-key',
		})

		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-1', name: 'X', url: null, typeId: 'type-login' },
			},
		})

		// Click directly on the actions wrapper which has @click.stop.
		await wrapper.find('.secret-list-item__actions').trigger('click')

		// The row's `open` event must NOT have fired.
		expect(wrapper.emitted('open')).toBeFalsy()
	})

	it('exposes an interactive role, tabindex and accessible name on the row', () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: {
					id: 's-1',
					name: 'GitHub PAT',
					url: null,
					typeId: 'type-api',
				},
			},
		})

		const row = wrapper.find('.secret-list-item')
		expect(row.attributes('role')).toBe('button')
		expect(row.attributes('tabindex')).toBe('0')
		// The accessible name is built via t('keepiq', 'Open {name}', ...).
		// The global test `t` stub does not interpolate placeholders, so we
		// assert the label is present and carries the "Open" affordance verb;
		// interpolation of the secret name happens at runtime.
		expect(row.attributes('aria-label')).toBeDefined()
		expect(row.attributes('aria-label')).toContain('Open')
	})

	it('emits `open` when the focused row is activated via Enter', async () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-77', name: 'AWS', url: null, typeId: 'type-login' },
			},
		})

		const row = wrapper.find('.secret-list-item')
		await row.trigger('keydown.enter')

		expect(wrapper.emitted('open')).toBeTruthy()
		expect(wrapper.emitted('open')[0]).toEqual(['s-77'])
	})

	it('emits `open` when the focused row is activated via Space', async () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-88', name: 'GCP', url: null, typeId: 'type-login' },
			},
		})

		const row = wrapper.find('.secret-list-item')
		await row.trigger('keydown.space')

		expect(wrapper.emitted('open')).toBeTruthy()
		expect(wrapper.emitted('open')[0]).toEqual(['s-88'])
	})

	it('keyboard activation inside the copy control does NOT bubble as `open`', async () => {
		const secretStore = useSecretStore()
		secretStore.fetchSecret = vi
			.fn()
			.mockResolvedValue({ id: 's-1', name: 'X', key: 'k' })

		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-1', name: 'X', url: null, typeId: 'type-login' },
			},
		})

		// Enter originating inside the actions wrapper (target !== the row).
		await wrapper.find('.secret-list-item__actions').trigger('keydown.enter')

		expect(wrapper.emitted('open')).toBeFalsy()
	})

	it('resolveKey() lazily decrypts via useSecretStore().fetchSecret', async () => {
		const secretStore = useSecretStore()
		secretStore.fetchSecret = vi.fn().mockResolvedValue({
			id: 's-1',
			name: 'X',
			key: 'decrypted-key',
		})

		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-1', name: 'X', url: null, typeId: 'type-login' },
			},
		})

		const value = await wrapper.vm.resolveKey()

		expect(secretStore.fetchSecret).toHaveBeenCalledWith('s-1')
		expect(value).toBe('decrypted-key')
	})
	it('marks a placeholder as awaiting its first fill', () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-1', name: 'Supplier API key', key: '' },
				requestState: 'awaiting-fill',
			},
		})

		expect(
			wrapper.find('[data-testid="secret-request-awaiting-fill"]').exists(),
		).toBe(true)
		expect(wrapper.text()).toContain('Waiting for someone to fill this in')
	})

	it('distinguishes a re-request from an empty placeholder', () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-2', name: 'Rotating token', key: 'CIPHER' },
				requestState: 're-request',
			},
		})

		// The consequences differ: this one still works until new values arrive.
		expect(
			wrapper.find('[data-testid="secret-request-re-request"]').exists(),
		).toBe(true)
		expect(wrapper.text()).toContain('New values requested')
		expect(wrapper.text()).not.toContain('Waiting for someone')
	})

	it('shows no indicator when there is no outstanding request', () => {
		const wrapper = mount(SecretListItem, {
			propsData: { secret: { id: 's-3', name: 'Plain', key: 'CIPHER' } },
		})

		expect(wrapper.find('.secret-list-item__request').exists()).toBe(false)
	})

	it('never renders a fill token in the row', () => {
		// The component takes a STATE, not the request, so a token cannot reach a
		// list row even if a caller had one to hand.
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-4', name: 'Tokenless', key: '' },
				requestState: 'awaiting-fill',
			},
		})

		expect(wrapper.html()).not.toMatch(/[a-f0-9]{32}/)
	})
})
