/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A secret's type is immutable once it exists.
 *
 * The edit dialog used to offer a free Type select, and the payload shape is
 * type-dependent: the card and identity composites render conditionally on
 * `isCard` / `isIdentity` and serialize into `key` differently. Switching a
 * Login to a Card therefore left the card fields empty and the login fields
 * unreachable, and the diff sent the new `typeId` anyway — a saved secret whose
 * declared type no longer matched its stored payload. Bitwarden and 1Password
 * both freeze type after creation for the same reason.
 *
 * These tests pin both halves: the dialog renders the type as text rather than
 * a control, and `typeId` can never reach the server from here — including when
 * something sets it behind the template's back, which is the only way a
 * regression could reintroduce the corruption.
 *
 * The CREATE dialog keeps its editable select; there is no existing payload to
 * invalidate there. SecretCreateDialog's own specs cover that.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	// Deliberately still stubbed: if a future change reintroduces an NcSelect
	// in this dialog, the "renders no select" assertion below must catch it
	// rather than fail to resolve the component.
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'],
		template: '<div class="stub-select" />',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		template: '<input :value="modelValue" :disabled="disabled" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" :value="modelValue" />',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

/**
 * Mount the dialog over a given secret.
 *
 * @param {object} secret The secret `fetchSecret` should resolve.
 * @return {object} The wrapper.
 */
async function mountOver(secret) {
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue(secret)

	const wrapper = mount(SecretEditDialog, {
		propsData: { secretId: secret.id },
		global: { stubs },
	})
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('SecretEditDialog — type is read-only', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()

		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'login', name: 'login', label: 'Login' },
			{ id: 'card', name: 'card', label: 'Credit card' },
		]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()

		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	it('shows the type as text, with no control to change it', async () => {
		const wrapper = await mountOver({
			id: 's1',
			name: 'Prod DB',
			typeId: 'login',
			key: 'Xk9#mQ2$vL7@pR4!zT6&',
			url: null,
			login: 'svc-acct',
		})

		expect(wrapper.find('[data-testid="secret-edit-type"]').text()).toBe('Login')
		expect(wrapper.find('.stub-select').exists()).toBe(false)
	})

	it('labels the type with its translated name, not the raw id', async () => {
		const wrapper = await mountOver({
			id: 's2',
			name: 'Company card',
			typeId: 'card',
			key: '{}',
			url: null,
			login: '',
		})

		expect(wrapper.find('[data-testid="secret-edit-type"]').text()).toBe(
			'Credit card',
		)
	})

	// The diff no longer has a typeId branch at all. Forcing the value proves
	// that: a dialog that still diffed it would send `typeId: 'card'` here, and
	// the server would store a Card whose payload is a login string.
	it('never sends typeId, even if something changes it behind the template', async () => {
		const wrapper = await mountOver({
			id: 's1',
			name: 'Prod DB',
			typeId: 'login',
			key: 'Xk9#mQ2$vL7@pR4!zT6&',
			url: null,
			login: 'svc-acct',
		})
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.typeId = 'card'
		wrapper.vm.name = 'Prod DB renamed'
		await wrapper.vm.submit()

		const diff = update.mock.calls[0][1]
		expect(diff.name).toBe('Prod DB renamed')
		expect('typeId' in diff).toBe(false)
	})

	it('still saves an unrelated edit normally', async () => {
		const wrapper = await mountOver({
			id: 's1',
			name: 'Prod DB',
			typeId: 'login',
			key: 'Xk9#mQ2$vL7@pR4!zT6&',
			url: null,
			login: 'svc-acct',
		})
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.login = 'new-acct'
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1].login).toBe('new-acct')
	})
})
