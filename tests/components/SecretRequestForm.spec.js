/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/secretRequest/SecretRequestForm.vue`.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#13.3
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretRequestForm from '../../src/components/secretRequest/SecretRequestForm.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('SecretRequestForm', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders a checkbox per available field', () => {
		const wrapper = mount(SecretRequestForm, {
			propsData: {
				secretId: 'sec-1',
				encryptionSuiteId: 'suite-1',
				availableFields: ['key', 'login', 'url'],
			},
		})
		expect(wrapper.find('[data-testid="field-key"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="field-login"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="field-url"]').exists()).toBe(true)
	})

	it('disables submit until at least one field is selected', async () => {
		const wrapper = mount(SecretRequestForm, {
			propsData: { secretId: 'sec-1', encryptionSuiteId: 'suite-1' },
		})
		expect(
			wrapper
				.find('[data-testid="secret-request-form-submit"]')
				.attributes('disabled'),
		).toBeDefined()

		await wrapper.find('[data-testid="field-key"]').setChecked(true)
		expect(
			wrapper
				.find('[data-testid="secret-request-form-submit"]')
				.attributes('disabled'),
		).toBeUndefined()
	})

	it('POSTs with the selected fields and shows the generated link', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'r1', token: 'tok-1', status: 'pending' },
		})
		const wrapper = mount(SecretRequestForm, {
			propsData: { secretId: 'sec-1', encryptionSuiteId: 'suite-1' },
		})
		await wrapper.find('[data-testid="field-key"]').setChecked(true)
		await wrapper.find('form').trigger('submit.prevent')
		// `flush()` is a SINGLE macrotask tick, and the submit path awaits an
		// async chain before it posts. One tick is not guaranteed to drain it,
		// so this asserted on a call that had not happened yet and failed about
		// once in twenty full-suite runs while passing every time in isolation.
		// waitFor retries until the call lands, and still fails if it never does.
		await flush()
		await vi.waitFor(() => expect(post).toHaveBeenCalled())
		const body = post.mock.calls[0][1]
		expect(body.requestedFields).toEqual(['key'])
		expect(body.secretId).toBe('sec-1')
		expect(
			wrapper.find('[data-testid="secret-request-form-link"]').text(),
		).toContain('tok-1')
	})

	it('shows the error message when the request rejects', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({
			response: { data: { message: 'boom' } },
		})
		const wrapper = mount(SecretRequestForm, {
			propsData: { secretId: 'sec-1', encryptionSuiteId: 'suite-1' },
		})
		await wrapper.find('[data-testid="field-key"]').setChecked(true)
		await wrapper.find('form').trigger('submit.prevent')
		await flush()
		expect(
			wrapper.find('[data-testid="secret-request-form-error"]').text(),
		).toContain('boom')
	})
})
