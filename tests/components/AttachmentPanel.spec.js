/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/AttachmentPanel.vue`.
 *
 * The hosting sidebar swaps the `:id` route segment WITHOUT remounting
 * (SecretDetailSidebar reloads from a `secretId` watcher for the same
 * reason), so the panel must refetch when its `secretId` prop changes —
 * a `mounted()`-only fetch leaves the previous secret's attachments on
 * screen for every secret opened after the first.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AttachmentPanel from '../../src/components/AttachmentPanel.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * One listing row as the server returns it. The wrapped key is garbage on
 * purpose: with no unlocked session the metadata decrypt fails and the row
 * renders as "(undecryptable attachment)" — the id-based testid is what
 * the assertions key on.
 *
 * @param {string} id The attachment id.
 * @return {object} The listing row.
 */
function row(id) {
	return {
		id,
		sizeBytes: 42,
		createdAt: '2026-08-31T00:00:00+00:00',
		wrappedFileKey: 'not-a-real-key',
		encryptedMetadata: 'not-real-metadata',
	}
}

describe('AttachmentPanel', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('lists the attachments of the mounted secret', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [row('att-a')] })
		const wrapper = mount(AttachmentPanel, {
			propsData: { secretId: 'sec-a' },
		})
		await flush()

		expect(wrapper.find('[data-testid="attachment-name-att-a"]').exists()).toBe(
			true,
		)
	})

	it('refetches when the secretId prop changes without a remount', async () => {
		const get = vi.spyOn(axios, 'get').mockImplementation((url) =>
			Promise.resolve({
				data: url.includes('sec-b') ? [row('att-b')] : [row('att-a')],
			}),
		)
		const wrapper = mount(AttachmentPanel, {
			propsData: { secretId: 'sec-a' },
		})
		await flush()
		expect(wrapper.find('[data-testid="attachment-name-att-a"]').exists()).toBe(
			true,
		)

		await wrapper.setProps({ secretId: 'sec-b' })
		await flush()

		const urls = get.mock.calls.map((c) => c[0])
		expect(urls.some((u) => u.includes('/secrets/sec-b/attachments'))).toBe(true)
		expect(wrapper.find('[data-testid="attachment-name-att-b"]').exists()).toBe(
			true,
		)
		// The first secret's rows never linger into the second.
		expect(wrapper.find('[data-testid="attachment-name-att-a"]').exists()).toBe(
			false,
		)
	})

	it('clears the previous list even when the new fetch fails', async () => {
		vi.spyOn(axios, 'get')
			.mockResolvedValueOnce({ data: [row('att-a')] })
			.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mount(AttachmentPanel, {
			propsData: { secretId: 'sec-a' },
		})
		await flush()
		expect(wrapper.find('[data-testid="attachment-name-att-a"]').exists()).toBe(
			true,
		)

		await wrapper.setProps({ secretId: 'sec-b' })
		await flush()

		// Showing sec-a's attachments against sec-b would be worse than
		// showing none: reset first, then surface the fetch error.
		expect(wrapper.find('[data-testid="attachment-name-att-a"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="attachment-error"]').exists()).toBe(true)
	})
})
