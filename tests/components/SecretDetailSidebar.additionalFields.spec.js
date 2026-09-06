/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the detail sidebar renders additional fields — including none.
 *
 * The empty case only became reachable with owner-editable additional fields.
 * Before that the sole writers were the application dialog, import, and a request
 * fill, all of which write at least one member; nobody could produce an EMPTY blob
 * from the UI. Removing the last member now does exactly that, and the spec says
 * re-opening the secret must show no additional fields and no error.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubAll = {
	NcAppSidebar: { template: '<aside><slot /></aside>' },
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	Delete: { template: '<span />' },
	Lock: { template: '<span />' },
	Pencil: { template: '<span />' },
	FolderMove: { template: '<span />' },
	ShareVariant: { template: '<span />' },
	CopyButton: { template: '<button />' },
	PasswordField: { template: '<input />' },
	ShareList: { template: '<div />' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the detail sidebar over a secret with the given decrypted blob.
 *
 * @param {object|null} additionalFields The decrypted members.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDetail(additionalFields) {
	const secret = {
		id: 's-1',
		name: 'Supplier API',
		typeId: 'login',
		key: 'value',
		additionalFields,
	}

	useSecretStore().fetchSecret = vi.fn().mockResolvedValue(secret)
	useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])

	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's-1' },
		global: {
			stubs: stubAll,
		},
	})
	await flush()
	await flush()

	return wrapper
}

describe('SecretDetailSidebar — additional fields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		window.OC = { currentUser: 'alice' }
	})

	it('renders the members a secret has', async () => {
		const wrapper = await mountDetail({ 'client-id': 'acme-4711' })

		expect(wrapper.vm.hasAdditionalFields).toBe(true)
		expect(wrapper.text()).toContain('client-id')
		expect(wrapper.text()).toContain('acme-4711')
	})

	it('shows NO additional-fields section for an empty blob', async () => {
		// `{}` is truthy AND an object, so a guard testing only those two things
		// reports "has additional fields" and renders the heading with nothing
		// beneath it. The user removed the last field; the section should be gone,
		// not empty.
		const wrapper = await mountDetail({})

		expect(wrapper.vm.hasAdditionalFields).toBe(false)
		expect(wrapper.text()).not.toContain('Additional fields')
	})

	it('shows no section when the secret never had any', async () => {
		const wrapper = await mountDetail(null)

		expect(wrapper.vm.hasAdditionalFields).toBe(false)
	})

	it('shows no section when the blob could not be parsed', async () => {
		// The store hands over a string when the ciphertext was not JSON. Rendering
		// a heading over an unusable value tells the user nothing.
		const wrapper = await mountDetail('not json')

		expect(wrapper.vm.hasAdditionalFields).toBe(false)
	})
})
