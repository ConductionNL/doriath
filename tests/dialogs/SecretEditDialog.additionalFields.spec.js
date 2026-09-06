/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Additional fields on the edit dialog.
 *
 * The blob is the storage unit, so there is no per-member update: any change
 * rewrites every member. Two things therefore have to hold, and both are pinned
 * here — the dialog must start from the CURRENT decrypted members (otherwise a save
 * silently drops the ones it never loaded), and removing the last member must send
 * an empty object rather than nothing at all, since "nothing" means "leave the
 * stored blob alone".
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
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'],
		template: '<div />',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		emits: ['update:modelValue'],
		template:
			'<input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
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
 * Mount the edit dialog over a secret whose decrypted blob holds `members`.
 *
 * @param {object|null} members The decrypted additionalFields, or null.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountOver(members) {
	const store = useSecretStore()
	store.fetchSecret = vi.fn().mockResolvedValue({
		id: 's1',
		name: 'Supplier API',
		typeId: 'login',
		key: 'Xk9#mQ2$vL7@pR4!zT6&',
		url: 'https://supplier.example',
		login: 'svc-acct',
		additionalFields: members,
	})

	const wrapper = mount(SecretEditDialog, {
		propsData: { secretId: 's1' },
		global: { stubs },
	})
	// mounted() → fetchPolicy + fetchTypes + load(); two ticks let all of it settle.
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('SecretEditDialog — additional fields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()

		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()

		// isLocked is a getter over cryptoKey; assigning it is a no-op.
		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	it('pre-fills the existing members from the decrypted blob', async () => {
		const wrapper = await mountOver({ 'client-id': 'acme-4711', tenant: 'acme' })

		expect(wrapper.vm.additionalFields).toEqual([
			{ name: 'client-id', value: 'acme-4711' },
			{ name: 'tenant', value: 'acme' },
		])
		expect(
			wrapper.find('[data-testid="additional-field-name-0"]').element.value,
		).toBe('client-id')
	})

	it('shows no members for a secret that has none', async () => {
		const wrapper = await mountOver(null)

		expect(wrapper.vm.additionalFields).toEqual([])
		expect(
			wrapper.find('[data-testid="additional-fields-empty"]').exists(),
		).toBe(true)
	})

	it('sends the whole blob when one member is renamed', async () => {
		const wrapper = await mountOver({ old: 'keepme', tenant: 'acme' })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.additionalFields = [
			{ name: 'new', value: 'keepme' },
			{ name: 'tenant', value: 'acme' },
		]
		await wrapper.vm.submit()

		// Every member, not just the renamed one — the blob is rewritten whole.
		expect(update.mock.calls[0][1].additionalFields).toEqual({
			new: 'keepme',
			tenant: 'acme',
		})
	})

	it('sends the blob when a value changes or a member is added', async () => {
		const wrapper = await mountOver({ tenant: 'acme' })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.additionalFields = [
			{ name: 'tenant', value: 'globex' },
			{ name: 'client-id', value: 'new-one' },
		]
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1].additionalFields).toEqual({
			tenant: 'globex',
			'client-id': 'new-one',
		})
	})

	it('sends an EMPTY object when the last member is removed', async () => {
		// Not null, and not omitted: either would be read as "leave the stored blob
		// alone", so the member the user just deleted would survive the save.
		const wrapper = await mountOver({ tenant: 'acme' })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.additionalFields = []
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1].additionalFields).toEqual({})
	})

	it('does not touch the blob when nothing about it changed', async () => {
		// A metadata-only edit must not re-encrypt and rewrite the members, both
		// because it is pointless work and because rewriting is what loses a
		// concurrent session's additions.
		const wrapper = await mountOver({ tenant: 'acme' })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.name = 'Renamed only'
		await wrapper.vm.submit()

		expect(update).toHaveBeenCalledTimes(1)
		expect('additionalFields' in update.mock.calls[0][1]).toBe(false)
	})

	it('alters no ciphertext when only the name changes', async () => {
		// "Edit metadata only" — a scenario in this requirement that nothing covered,
		// vitest or Playwright. It is not about additional fields as such, but it is
		// the same diff logic: a metadata-only save must carry NO sensitive field, or
		// every rename would re-encrypt the value, the login and the whole member
		// blob for nothing — and rewriting the blob is exactly what loses a
		// concurrent session's additions.
		const wrapper = await mountOver({ tenant: 'acme' })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.name = 'Renamed'
		await wrapper.vm.submit()

		const diff = update.mock.calls[0][1]
		expect(diff.name).toBe('Renamed')
		expect('key' in diff).toBe(false)
		expect('login' in diff).toBe(false)
		expect('additionalFields' in diff).toBe(false)
	})
})
