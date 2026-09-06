/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/views/SecretRequestFill.vue` — the public
 * recipient-facing fill-in page.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#13.2
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async () => 'PUBKEY_HANDLE'),
	rsaEncrypt: vi.fn(async (value) => `ENC(${value})`),
}))

import SecretRequestFill from '../../src/views/SecretRequestFill.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('SecretRequestFill', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders an input per requested field once the token resolves', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				token: 'tok-1',
				status: 'pending',
				requested_fields: ['key', 'login'],
				public_certificate: 'PEM',
			},
		})
		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()
		expect(wrapper.find('[data-testid="fill-field-key"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="fill-field-login"]').exists()).toBe(true)
	})

	it('uses type=password for sensitive field names', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				token: 'tok-1',
				status: 'pending',
				requested_fields: ['password', 'url'],
				public_certificate: 'PEM',
			},
		})
		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()
		expect(
			wrapper.find('[data-testid="fill-field-password"]').attributes('type'),
		).toBe('password')
		expect(
			wrapper.find('[data-testid="fill-field-url"]').attributes('type'),
		).toBe('text')
	})

	it('renders the "unavailable" message when the request is locked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				token: 'tok-1',
				status: 'locked',
				requested_fields: [],
				public_certificate: 'PEM',
			},
		})
		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()
		expect(wrapper.find('[data-testid="fill-unavailable"]').text()).toContain(
			'temporarily unavailable',
		)
	})

	it('encrypts on submit and shows the success message', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				token: 'tok-1',
				status: 'pending',
				requested_fields: ['key'],
				public_certificate: 'PEM',
			},
		})
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { status: 'fulfilled' } })
		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()
		await wrapper.find('[data-testid="fill-field-key"]').setValue('p4ss')
		await wrapper.find('[data-testid="fill-form"]').trigger('submit.prevent')
		// `flush()` is a SINGLE macrotask tick, and the submit path awaits an
		// async chain before it posts. One tick is not guaranteed to drain it,
		// so this asserted on a call that had not happened yet and failed about
		// once in twenty full-suite runs while passing every time in isolation.
		// waitFor retries until the call lands, and still fails if it never does.
		await flush()
		await vi.waitFor(() => expect(post).toHaveBeenCalled())
		const body = post.mock.calls[0][1]
		expect(body.encryptedFields.key).toBe('ENC(p4ss)')
		expect(wrapper.find('[data-testid="fill-success"]').exists()).toBe(true)
	})

	it('renders the load-error block when the token resolution rejects', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({
			response: { data: { message: 'not found' } },
		})
		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()
		expect(wrapper.find('[data-testid="fill-load-error"]').text()).toContain(
			'not found',
		)
	})
	it('shows the translatable message for the reason, not the server English', async () => {
		// Measured in a browser on 2026-08-19: a real expired link rendered
		// "Request has expired" — the PHP exception string. The translated
		// "This request has expired." existed in this component and was
		// unreachable, because the client had only prose to switch on. The
		// server now sends a `reason`, and this asserts the recipient reads
		// the translated sentence rather than the server's.
		vi.spyOn(axios, 'get').mockRejectedValue({
			response: {
				data: { message: 'Request has expired', reason: 'expired' },
			},
		})
		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()

		const text = wrapper.find('[data-testid="fill-load-error"]').text()
		expect(text).toContain('This request has expired.')
		expect(text).not.toContain('Request has expired"')
	})

	it('falls back to the server message when the reason is unknown', async () => {
		// An older server, or a failure the server could not classify. An
		// untranslated sentence beats a blank page, so the fallback must survive.
		vi.spyOn(axios, 'get').mockRejectedValue({
			response: {
				data: {
					message: 'Something specific went wrong',
					reason: 'unknown',
				},
			},
		})
		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()

		expect(wrapper.find('[data-testid="fill-load-error"]').text()).toContain(
			'Something specific went wrong',
		)
	})

	it('refuses to run outside a secure context, with a reason a stranger can act on', async () => {
		// Reproduces what Robert hit: crypto.subtle is undefined on plain http, so
		// the page used to die on importKey with "Cannot read properties of
		// undefined" — meaningless to an external recipient.
		const realSecure = window.isSecureContext
		Object.defineProperty(window, 'isSecureContext', {
			value: false,
			configurable: true,
		})

		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()

		expect(wrapper.find('[data-testid="fill-insecure-context"]').exists()).toBe(
			true,
		)
		expect(wrapper.text()).toContain('https://')
		// And the form must not be offered: submitting it could not work.
		expect(wrapper.findAll('input:not([type=hidden])').length).toBe(0)

		Object.defineProperty(window, 'isSecureContext', {
			value: realSecure,
			configurable: true,
		})
	})
})
