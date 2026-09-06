/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/CopyButton.vue`.
 *
 * CopyButton is the shared copy-to-clipboard primitive used by every
 * password field, secret list row, and key-generator dialog. It MUST:
 *  - call navigator.clipboard.writeText with the resolved value
 *  - support either a direct `value` prop or an async `resolve` function
 *    (used so decryption only happens when the user actually copies)
 *  - emit `copied` once the write succeeds
 *  - auto-clear the clipboard after `clearAfter` seconds (default 30;
 *    0 disables the clear)
 *  - fall back to document.execCommand('copy') when navigator.clipboard
 *    is unavailable (legacy IE/quirks paths)
 *
 * @spec openspec/changes/implement-secrets/tasks.md#7.6
 * @spec openspec/changes/implement-secrets/tasks.md#13.3
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CopyButton from '../../src/components/CopyButton.vue'

// Helper: wait for any pending promises to settle (microtask drain).
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('CopyButton', () => {
	let writeTextSpy

	beforeEach(() => {
		writeTextSpy = vi.fn().mockResolvedValue()
		// jsdom does not ship navigator.clipboard — install a fake.
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: writeTextSpy },
			configurable: true,
		})
		// jsdom does not implement execCommand.
		document.execCommand = vi.fn().mockReturnValue(true)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('writes the static `value` prop to the clipboard on click', async () => {
		const wrapper = mount(CopyButton, {
			propsData: { value: 'hello-world' },
		})

		await wrapper.find('button').trigger('click')
		await flush()

		expect(writeTextSpy).toHaveBeenCalledOnce()
		expect(writeTextSpy).toHaveBeenCalledWith('hello-world')
	})

	it('calls the async `resolve` function and copies its return value', async () => {
		const resolve = vi.fn().mockResolvedValue('decrypted-key')
		const wrapper = mount(CopyButton, {
			propsData: { value: 'ignored-when-resolve-set', resolve },
		})

		await wrapper.find('button').trigger('click')
		await flush()

		expect(resolve).toHaveBeenCalledOnce()
		expect(writeTextSpy).toHaveBeenCalledWith('decrypted-key')
	})

	it('emits `copied` after a successful clipboard write', async () => {
		const wrapper = mount(CopyButton, {
			propsData: { value: 'x' },
		})

		await wrapper.find('button').trigger('click')
		await flush()

		expect(wrapper.emitted('copied')).toBeTruthy()
		expect(wrapper.emitted('copied')).toHaveLength(1)
	})

	it('auto-clears the clipboard after `clearAfter` seconds', async () => {
		// Use a tiny 0.05s timer so real setTimeout fires immediately.
		const wrapper = mount(CopyButton, {
			propsData: { value: 'secret', clearAfter: 0.05 },
		})

		await wrapper.find('button').trigger('click')
		await flush()

		expect(writeTextSpy).toHaveBeenCalledWith('secret')

		// Wait past the clear timer (50ms + 50ms slack).
		await new Promise((resolve) => setTimeout(resolve, 100))

		expect(writeTextSpy.mock.calls.map((c) => c[0])).toEqual(['secret', ''])
	})

	it('does NOT auto-clear when `clearAfter` is 0', async () => {
		const wrapper = mount(CopyButton, {
			propsData: { value: 'secret', clearAfter: 0 },
		})

		await wrapper.find('button').trigger('click')
		await flush()

		expect(writeTextSpy).toHaveBeenCalledWith('secret')

		// Wait long enough that ANY clear timer would have fired.
		await new Promise((resolve) => setTimeout(resolve, 100))

		// Still just the one call — the empty-string clear must not fire.
		expect(writeTextSpy).toHaveBeenCalledOnce()
	})

	it('falls back to document.execCommand when navigator.clipboard is absent', async () => {
		Object.defineProperty(global.navigator, 'clipboard', {
			value: undefined,
			configurable: true,
		})

		const wrapper = mount(CopyButton, {
			propsData: { value: 'legacy-path' },
		})
		await wrapper.find('button').trigger('click')
		await flush()

		expect(document.execCommand).toHaveBeenCalledWith('copy')
	})

	it('falls back to execCommand when navigator.clipboard.writeText throws', async () => {
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
			configurable: true,
		})

		const wrapper = mount(CopyButton, {
			propsData: { value: 'fallback' },
		})
		await wrapper.find('button').trigger('click')
		await flush()

		expect(document.execCommand).toHaveBeenCalledWith('copy')
	})
})
