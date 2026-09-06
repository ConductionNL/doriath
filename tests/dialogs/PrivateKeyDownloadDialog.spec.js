/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/PrivateKeyDownloadDialog.vue`.
 *
 * This dialog shows a ONE-TIME private key that cannot be recovered. Its first
 * cut was a bare <section role="dialog"> in document flow, which painted the
 * key below the fold where nobody saw it. What these tests pin:
 *
 *  - The dialog renders through NcDialog with `noClose`, so it is a real
 *    overlay AND the library's own escape hatches (close button, Esc, outside
 *    click) stay shut — the acknowledgment gate is the only way out.
 *  - Dismiss is disabled until the acknowledgment box is ticked.
 *  - Reopening for a new key starts unacknowledged again.
 *
 * The `@nextcloud/vue` design primitives resolve to the flat stub dict in
 * `tests/vitest/stubs/nextcloud-vue.js` (vitest alias): NcDialog renders its
 * slots in place (no teleport), and undeclared props like `noClose` fall
 * through onto its root element as attributes.
 *
 * @spec openspec/specs/application-mgmt/spec.md#requirement-encryptionsuite-via-csr
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import PrivateKeyDownloadDialog from '../../src/dialogs/PrivateKeyDownloadDialog.vue'

const KEY = '-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----'

/**
 * Mount the dialog open with a key, as the parent views render it.
 *
 * @param {object} propsData Extra props to merge over the defaults.
 * @return {object} The wrapper.
 */
function mountDialog(propsData = {}) {
	return mount(PrivateKeyDownloadDialog, {
		propsData: { open: true, privateKey: KEY, ...propsData },
	})
}

describe('PrivateKeyDownloadDialog', () => {
	it('renders as an NcDialog overlay with every library escape hatch shut', () => {
		const wrapper = mountDialog()
		const dialog = wrapper.findComponent({ name: 'NcDialog' })
		expect(dialog.exists()).toBe(true)
		expect(dialog.attributes('data-open')).toBe('true')
		// `noClose` is not declared on the stub, so it falls through as an
		// attribute — its presence pins that the real dialog suppresses the
		// close button, Esc and outside clicks.
		expect(dialog.attributes('noclose')).toBe('true')
	})

	it('presents the key area and the unmissable one-time warning', () => {
		const wrapper = mountDialog()
		// Masked by default (review call) — the reveal test below covers
		// the plaintext path; here the block must be present and key-sized.
		const textarea = wrapper.find('[data-testid="private-key-textarea"]')
		expect(textarea.exists()).toBe(true)
		expect(textarea.element.value).toHaveLength(KEY.length)
		expect(wrapper.find('[data-testid="private-key-warning"]').text()).toContain(
			'cannot be recovered',
		)
	})

	it('keeps Dismiss locked until the acknowledgment is ticked', async () => {
		const wrapper = mountDialog()
		const dismiss = wrapper.find('[data-testid="private-key-dismiss"]')
		expect(dismiss.attributes('disabled')).toBeDefined()

		wrapper
			.findComponent({ name: 'NcCheckboxRadioSwitch' })
			.vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		expect(dismiss.attributes('disabled')).toBeUndefined()
		await dismiss.trigger('click')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('starts unacknowledged again when reopened for a new key', async () => {
		const wrapper = mountDialog()
		wrapper
			.findComponent({ name: 'NcCheckboxRadioSwitch' })
			.vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(
			wrapper
				.find('[data-testid="private-key-dismiss"]')
				.attributes('disabled'),
		).toBeDefined()
	})

	// Copy goes through CopyButton so the clipboard is CLEARED again on its
	// timer (review call): a one-time key must not outlive its dialog in
	// the paste buffer of a shared workstation.
	it('copies the key and auto-clears the clipboard afterwards', async () => {
		vi.useFakeTimers()
		try {
			const writeText = vi.fn().mockResolvedValue(undefined)
			Object.assign(navigator, { clipboard: { writeText } })

			const wrapper = mountDialog()
			await wrapper.find('[data-testid="private-key-copy"]').trigger('click')
			await wrapper.vm.$nextTick()

			expect(writeText).toHaveBeenCalledWith(KEY)

			await vi.advanceTimersByTimeAsync(30_000)
			expect(writeText).toHaveBeenLastCalledWith('')
		} finally {
			vi.useRealTimers()
		}
	})

	// The key renders MASKED until an explicit reveal (review call): the
	// dialog pops open unprompted after registration, possibly mid-
	// screenshare — not one character may show without consent.
	it('masks the key until revealed, and re-masks on hide', async () => {
		const wrapper = mountDialog()
		const textarea = wrapper.find('[data-testid="private-key-textarea"]')

		expect(textarea.element.value).not.toContain('PRIVATE KEY')
		expect(textarea.element.value).toContain('•')
		// The line structure survives, so revealing does not reflow.
		expect(textarea.element.value.split('\n')).toHaveLength(
			KEY.split('\n').length,
		)

		await wrapper.find('[data-testid="private-key-reveal"]').trigger('click')
		expect(textarea.element.value).toBe(KEY)

		await wrapper.find('[data-testid="private-key-reveal"]').trigger('click')
		expect(textarea.element.value).not.toContain('PRIVATE KEY')
	})

	// Belt and braces for the noClose gate (review call): the library's Esc
	// handling has historically routed through internals rather than
	// noClose, so the dialog swallows Escape itself.
	it('swallows Escape before it can reach the library', () => {
		const wrapper = mountDialog()
		const container = wrapper.find('.private-key').element

		const esc = new KeyboardEvent('keydown', {
			key: 'Escape',
			bubbles: true,
			cancelable: true,
		})
		container.dispatchEvent(esc)

		expect(esc.defaultPrevented).toBe(true)
		expect(wrapper.emitted('close')).toBeFalsy()
	})
})
