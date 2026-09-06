/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/SecretRequestCreateDialog.vue`.
 *
 * What these lock down:
 *  - the available-fields list grows from the secret's `additional_fields_keys`
 *    (the key/login defaults are always present);
 *  - submitting the form delegates to `useSecretRequestStore().createRequest`
 *    with the selected `requested_fields` + ISO `expires_at`;
 *  - the re-request mode dispatches the `createReRequest` action instead and
 *    forwards the secret's encryption_suite_id;
 *  - on success the dialog populates a `fillUrl` from the returned token and
 *    emits a `created` event with the store's response;
 *  - copyUrl() writes the fill URL to the clipboard and flips `copied`;
 *  - submit failures surface in the `.secret-request-create-dialog__error`
 *    pane and clear the `submitting` flag.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#13.3
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretRequestCreateDialog from '../../src/dialogs/SecretRequestCreateDialog.vue'
import { useSecretRequestStore } from '../../src/store/modules/secretRequest.js'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template:
			'<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
}

describe('SecretRequestCreateDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: vi.fn().mockResolvedValue() },
			configurable: true,
		})
	})

	it('renders the default key + login fields plus any additional_fields_keys', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: {
					id: 'secret-1',
					additional_fields_keys: ['totp', 'pin'],
				},
			},
			global: { stubs: ncStubs },
		})

		const fields = wrapper.vm.availableFields.map((f) => f.key)
		// 'url' is part of the baseline set now — the backend has always
		// stored it, the dialog simply never offered it.
		expect(fields).toEqual(['key', 'login', 'url', 'totp', 'pin'])
	})

	it('submit(): delegates to createRequest with selected fields + ISO expiry', async () => {
		const store = useSecretRequestStore()
		store.createRequest = vi.fn().mockResolvedValue({
			id: 'req-1',
			token: 'tok-abc',
		})

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: { id: 'secret-1' },
			},
			global: { stubs: ncStubs },
		})

		wrapper.vm.requestedFields = ['key', 'login']
		wrapper.vm.expiresAt = '2026-12-31T23:59'

		await wrapper.vm.submit()

		expect(store.createRequest).toHaveBeenCalledTimes(1)
		const payload = store.createRequest.mock.calls[0][0]
		// camelCase, matching what the store forwards and what the Nextcloud
		// router binds. This assertion used to demand snake_case, which the store
		// read as `undefined` — so the test passed against a mocked store while
		// the real POST went out empty and the endpoint answered 400.
		expect(payload).toMatchObject({
			secretId: 'secret-1',
			requestedFields: ['key', 'login'],
			isReRequest: false,
		})
		expect(payload).not.toHaveProperty('secret_id')
		expect(typeof payload.expiresAt).toBe('string')
		expect(payload.expiresAt.endsWith('Z')).toBe(true)

		// fillUrl is populated from the response token.
		// The anonymous shell as a PATH, NOT /apps/keepiq/share/request/<token>
		// (the recipient has no account, and that form answers 401 for them)
		// and NOT the retired '#/share/request/' hash form (the
		// createWebHistory router never reads the fragment).
		expect(wrapper.vm.fillUrl).toContain(
			'/apps/keepiq/public/share/request/tok-abc',
		)
		expect(wrapper.vm.fillUrl).not.toContain('#/')
		expect(wrapper.vm.fillUrl).not.toMatch(/apps\/keepiq\/share\/request\//)

		// `created` event is emitted with the store response.
		const events = wrapper.emitted('created')
		expect(events).toBeTruthy()
		expect(events[0][0]).toMatchObject({ id: 'req-1', token: 'tok-abc' })

		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('submit() in re-request mode calls createReRequest with the suite ID', async () => {
		const store = useSecretRequestStore()
		store.createReRequest = vi.fn().mockResolvedValue({
			id: 'req-2',
			token: 'tok-rerequest',
		})

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				isReRequest: true,
				secret: {
					id: 'secret-1',
					encryption_suite_id: 'suite-9',
				},
			},
			global: { stubs: ncStubs },
		})

		wrapper.vm.requestedFields = ['key']
		wrapper.vm.expiresAt = ''

		await wrapper.vm.submit()

		expect(store.createReRequest).toHaveBeenCalledWith(
			'secret-1',
			'suite-9',
			['key'],
			null, // no expiry → null (not empty string)
		)
		expect(wrapper.vm.fillUrl).toContain(
			'/apps/keepiq/public/share/request/tok-rerequest',
		)
	})

	it('copyUrl(): writes the fillUrl to the clipboard and flips the copied flag', async () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: { id: 'secret-1' },
			},
			global: { stubs: ncStubs },
		})

		wrapper.vm.fillUrl = 'http://nc.test/apps/keepiq/public/share/request/tok-1'
		await wrapper.vm.copyUrl()

		expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
			'http://nc.test/apps/keepiq/public/share/request/tok-1',
		)
		expect(wrapper.vm.copied).toBe(true)
	})

	it('submit() surfaces server errors and clears the submitting flag', async () => {
		const store = useSecretRequestStore()
		store.createRequest = vi.fn().mockRejectedValue({
			response: { data: { message: 'Pending request already exists' } },
		})

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: { id: 'secret-1' },
			},
			global: { stubs: ncStubs },
		})

		await wrapper.vm.submit()

		expect(wrapper.vm.error).toBe('Pending request already exists')
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.vm.fillUrl).toBe('')
		expect(wrapper.emitted('created')).toBeFalsy()
	})
	it('offers every field a secret supports, including url', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: { id: 'secret-1', additional_fields_keys: ['api-token'] },
			},
			global: { stubs: ncStubs },
		})

		const keys = wrapper.vm.availableFields.map((f) => f.key)
		// `url` was absent before, so a user could not request it at all even
		// though the backend stores it.
		expect(keys).toEqual(['key', 'login', 'url', 'api-token'])
		// It must be marked plaintext — it is stored searchable, not encrypted.
		expect(
			wrapper.vm.availableFields.find((f) => f.key === 'url').plaintext,
		).toBe(true)
	})

	it('addCustomField(): names a field the secret does not have yet and ticks it', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: { open: true, secret: { id: 'secret-1' } },
			global: { stubs: ncStubs },
		})

		wrapper.vm.customFieldInput = '  zgw-client-id  '
		wrapper.vm.addCustomField()

		// Trimmed, listed, ticked, and the input cleared for the next one.
		expect(wrapper.vm.availableFields.map((f) => f.key)).toContain(
			'zgw-client-id',
		)
		expect(wrapper.vm.requestedFields).toContain('zgw-client-id')
		expect(wrapper.vm.customFieldInput).toBe('')
		expect(wrapper.vm.customFieldError).toBe('')
	})

	it('addCustomField(): refuses built-in names instead of silently misrouting them', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: { open: true, secret: { id: 'secret-1' } },
			global: { stubs: ncStubs },
		})

		for (const reserved of ['key', 'login', 'url']) {
			wrapper.vm.customFieldInput = reserved
			wrapper.vm.addCustomField()

			expect(wrapper.vm.customFieldError).not.toBe('')
			expect(wrapper.vm.customFields).not.toContain(reserved)
		}
	})

	it('addCustomField(): refuses a duplicate and an empty name', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: { id: 'secret-1', additional_fields_keys: ['api-token'] },
			},
			global: { stubs: ncStubs },
		})

		wrapper.vm.customFieldInput = 'api-token'
		wrapper.vm.addCustomField()
		expect(wrapper.vm.customFieldError).not.toBe('')
		expect(wrapper.vm.customFields).toHaveLength(0)

		wrapper.vm.customFieldInput = '   '
		wrapper.vm.customFieldError = ''
		wrapper.vm.addCustomField()
		// Empty input is a no-op, not an error message.
		expect(wrapper.vm.customFieldError).toBe('')
		expect(wrapper.vm.customFields).toHaveLength(0)
	})

	it('closing resets the custom field state', async () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: { open: true, secret: { id: 'secret-1' } },
			global: { stubs: ncStubs },
		})

		wrapper.vm.customFieldInput = 'extra'
		wrapper.vm.addCustomField()
		expect(wrapper.vm.customFields).toHaveLength(1)

		await wrapper.vm.onClose()

		expect(wrapper.vm.customFields).toEqual([])
		expect(wrapper.vm.customFieldInput).toBe('')
		expect(wrapper.vm.requestedFields).toEqual(['key'])
	})
	it('submits a FRESH request with no secret prop, sending name instead of secretId', async () => {
		const store = useSecretRequestStore()
		store.createRequest = vi
			.fn()
			.mockResolvedValue({ id: 'req-f', token: 'tok-f' })

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.newName = 'Supplier API key'
		wrapper.vm.requestedFields = ['key', 'url']
		await wrapper.vm.submit()

		const payload = store.createRequest.mock.calls[0][0]
		// The server creates the placeholder and derives the suite from it, so the
		// client sends neither.
		expect(payload.secretId).toBeUndefined()
		expect(payload.encryptionSuiteId).toBeUndefined()
		expect(payload).toMatchObject({
			name: 'Supplier API key',
			requestedFields: ['key', 'url'],
			isReRequest: false,
		})
	})

	it('refuses a fresh request with no name instead of creating a nameless placeholder', async () => {
		const store = useSecretRequestStore()
		store.createRequest = vi.fn()

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: { open: true },
			global: { stubs: ncStubs },
		})

		wrapper.vm.newName = '   '
		await wrapper.vm.submit()

		expect(store.createRequest).not.toHaveBeenCalled()
		expect(wrapper.vm.error).not.toBe('')
	})

	it('does not pre-select a field that already holds a value', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: {
					id: 's-1',
					key: 'CIPHER',
					login: '',
					additionalFields: {},
				},
			},
			global: { stubs: ncStubs },
		})

		// `key` is filled, so it must not be ticked: the recipient cannot decline a
		// requested field, so pre-selecting it would compel an overwrite.
		expect(wrapper.vm.requestedFields).not.toContain('key')
		expect(wrapper.vm.availableFields.find((f) => f.key === 'key').filled).toBe(
			true,
		)
	})

	it('detects filled additional-field members from the decrypted blob', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: {
					id: 's-2',
					key: '',
					additionalFields: { 'client-id': 'abc', 'client-secret': '' },
				},
			},
			global: { stubs: ncStubs },
		})

		const byKey = Object.fromEntries(
			wrapper.vm.availableFields.map((f) => [f.key, f.filled]),
		)
		expect(byKey['client-id']).toBe(true)
		expect(byKey['client-secret']).toBe(false)
	})

	it('a re-request still pre-selects the filled key, because replacing is the point', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				isReRequest: true,
				secret: { id: 's-3', key: 'CIPHER' },
			},
			global: { stubs: ncStubs },
		})

		expect(wrapper.vm.requestedFields).toContain('key')
	})
	it('pre-fills a suggested expiry so requests are not perpetual by default', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: { open: true, secret: { id: 's-1' } },
			global: { stubs: ncStubs },
		})

		// `expires_at` has exactly one source — this field. While it defaulted to
		// empty, almost nothing expired and the sweeper had nothing to act on.
		expect(wrapper.vm.expiresAt).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/)

		const suggested = new Date(wrapper.vm.expiresAt)
		const days = (suggested.getTime() - Date.now()) / 86400000
		expect(days).toBeGreaterThan(13)
		expect(days).toBeLessThan(15)
	})

	it('formats the suggestion in LOCAL time, not UTC', () => {
		// datetime-local carries no timezone, so a UTC string would display a time
		// the user never chose on any instance away from Greenwich.
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: { open: true, secret: { id: 's-1' } },
			global: { stubs: ncStubs },
		})

		const expected = new Date()
		expected.setDate(expected.getDate() + 14)
		const pad = (n) => String(n).padStart(2, '0')
		expect(wrapper.vm.expiresAt.slice(0, 10)).toBe(
			`${expected.getFullYear()}-${pad(expected.getMonth() + 1)}-${pad(expected.getDate())}`,
		)
	})

	it('the suggestion can be cleared, so a perpetual request stays one action away', async () => {
		const store = useSecretRequestStore()
		store.createRequest = vi.fn().mockResolvedValue({ id: 'r', token: 't' })

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: { id: 's-1', encryption_suite_id: 'suite-1' },
			},
			global: { stubs: ncStubs },
		})

		wrapper.vm.expiresAt = ''
		wrapper.vm.requestedFields = ['key']
		await wrapper.vm.submit()

		expect(store.createRequest.mock.calls[0][0].expiresAt).toBeNull()
	})
})
