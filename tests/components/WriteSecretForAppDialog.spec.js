/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/WriteSecretForAppDialog.vue`.
 *
 * Locks the W30/W31 contract for the "write a secret for an application"
 * flow: the dialog collects plaintext in component state, calls
 * `useApplicationStore.writeSecretForApplication()` with the trimmed
 * payload, then wipes the plaintext value field and surfaces the
 * confirmation NcNoteCard. The plaintext key MUST NEVER appear in the
 * POST body that hits the wire — the store action is responsible for
 * the in-browser RSA-OAEP-SHA256 encrypt step. This spec verifies the
 * dialog hands the encrypt step its inputs and reacts correctly to the
 * resolved/rejected store call.
 *
 * Mounted under jsdom via @vue/test-utils with shallow stubs for the
 * @nextcloud/vue primitives — same pattern as SecretShareDialog.spec.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-15.5
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.6
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import WriteSecretForAppDialog from '../../src/dialogs/WriteSecretForAppDialog.vue'
import { useApplicationStore } from '../../src/store/modules/application.js'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template:
			'<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['type', 'disabled'],
		template:
			'<button :disabled="disabled" :data-testid="$attrs[\'data-testid\']" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template:
			'<div class="nc-note-card-stub" :data-type="type" :data-testid="$attrs[\'data-testid\']"><slot /></div>',
	},
	NcTextField: {
		props: ['value', 'label', 'required'],
		template:
			'<input class="nc-text-field-stub" :data-testid="$attrs[\'data-testid\']" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
	},
	NcPasswordField: {
		props: ['value', 'label', 'required'],
		template:
			'<input type="password" class="nc-password-field-stub" :data-testid="$attrs[\'data-testid\']" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
	},
	NcTextArea: {
		props: ['value', 'label', 'rows'],
		template:
			'<textarea class="nc-text-area-stub" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
	},
}

describe('WriteSecretForAppDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('mounts with the action button disabled until name + value are filled', async () => {
		const wrapper = mount(WriteSecretForAppDialog, {
			propsData: {
				open: true,
				applicationId: 'app-1',
				applicationName: 'Test App',
			},
			global: { stubs: ncStubs },
		})

		const submit = wrapper.find('[data-testid="write-secret-submit"]')
		expect(submit.exists()).toBe(true)
		expect(submit.attributes('disabled')).toBeDefined()

		await wrapper.setData({ name: 'GitHub PAT', value: 'plaintext-xyz' })

		expect(submit.attributes('disabled')).toBeUndefined()
	})

	it('submit: delegates to useApplicationStore.writeSecretForApplication with trimmed plaintext payload', async () => {
		const store = useApplicationStore()
		store.writeSecretForApplication = vi.fn().mockResolvedValue({
			id: 'sec-1',
			ownerType: 'application',
			ownerId: 'app-1',
		})

		const wrapper = mount(WriteSecretForAppDialog, {
			propsData: {
				open: true,
				applicationId: 'app-1',
				applicationName: 'Test App',
			},
			global: { stubs: ncStubs },
		})

		await wrapper.setData({
			name: '  GitHub PAT  ',
			url: 'https://github.com',
			login: 'git-user',
			value: 'ghp_AAA',
			additionalFields: '',
		})

		await wrapper.vm.submit()

		expect(store.writeSecretForApplication).toHaveBeenCalledTimes(1)
		const [appId, payload] = store.writeSecretForApplication.mock.calls[0]
		expect(appId).toBe('app-1')
		expect(payload.name).toBe('GitHub PAT') // trimmed
		expect(payload.url).toBe('https://github.com')
		expect(payload.login).toBe('git-user')
		expect(payload.key).toBe('ghp_AAA') // store handles the encrypt step
		expect(payload.additionalFields).toBeNull()

		// Plaintext value wiped from component state to prevent linger.
		expect(wrapper.vm.value).toBe('')
		// Confirmation surface flipped on.
		expect(wrapper.vm.success).toBe(true)
		// Emits `written` with the store result so the parent can refresh.
		expect(wrapper.emitted('written')).toBeTruthy()
		expect(wrapper.emitted('written')[0][0]).toEqual({
			id: 'sec-1',
			ownerType: 'application',
			ownerId: 'app-1',
		})
	})

	it('submit: surfaces a store error on the NcNoteCard without flipping success', async () => {
		const store = useApplicationStore()
		store.writeSecretForApplication = vi.fn().mockRejectedValue({
			response: {
				data: { message: 'Application has no active EncryptionSuite' },
			},
		})

		const wrapper = mount(WriteSecretForAppDialog, {
			propsData: {
				open: true,
				applicationId: 'app-1',
				applicationName: 'Test App',
			},
			global: { stubs: ncStubs },
		})

		await wrapper.setData({ name: 'GitHub PAT', value: 'ghp_AAA' })

		await wrapper.vm.submit()

		expect(wrapper.vm.success).toBe(false)
		expect(wrapper.vm.error).toBe('Application has no active EncryptionSuite')
		expect(wrapper.emitted('written')).toBeFalsy()
	})

	it('submit: parses JSON additionalFields and forwards the object to the store', async () => {
		const store = useApplicationStore()
		store.writeSecretForApplication = vi.fn().mockResolvedValue({ id: 'sec-2' })

		const wrapper = mount(WriteSecretForAppDialog, {
			propsData: {
				open: true,
				applicationId: 'app-1',
			},
			global: { stubs: ncStubs },
		})

		await wrapper.setData({
			name: 'AWS',
			value: 'akia-secret',
			additionalFields: '{"region":"eu-west-1","note":"prod"}',
		})

		await wrapper.vm.submit()

		const [, payload] = store.writeSecretForApplication.mock.calls[0]
		expect(payload.additionalFields).toEqual({
			region: 'eu-west-1',
			note: 'prod',
		})
	})

	it('onUpdateOpen(false): resets every plaintext field and emits close', async () => {
		const wrapper = mount(WriteSecretForAppDialog, {
			propsData: {
				open: true,
				applicationId: 'app-1',
				applicationName: 'Test App',
			},
			global: { stubs: ncStubs },
		})

		await wrapper.setData({
			name: 'leak-me',
			url: 'https://example.com',
			login: 'me',
			value: 'plaintext-do-not-leak',
			additionalFields: '{"a":1}',
			error: 'stale',
			success: true,
		})

		wrapper.vm.onUpdateOpen(false)

		expect(wrapper.vm.name).toBe('')
		expect(wrapper.vm.url).toBe('')
		expect(wrapper.vm.login).toBe('')
		expect(wrapper.vm.value).toBe('')
		expect(wrapper.vm.additionalFields).toBe('')
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.success).toBe(false)
		expect(wrapper.emitted('close')).toBeTruthy()
	})
})
