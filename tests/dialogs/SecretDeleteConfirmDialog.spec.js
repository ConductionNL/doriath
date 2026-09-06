/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/SecretDeleteConfirmDialog.vue`.
 *
 * The sidebar's `…` → delete used to call `deleteSecret()` straight from the
 * click handler: the one irreversible action in the app, with no confirmation,
 * on the path people actually use (list rows carry no `…` menu of their own).
 * What these tests pin is the part that makes the guard worth having:
 *
 *  - Nothing is sent on mount. The store is only touched once the confirm
 *    button is pressed, so opening the dialog can never delete anything.
 *  - Cancel closes WITHOUT deleting, and never routes through submit(). A
 *    refactor that wired Cancel into the delete path would re-introduce the
 *    exact regression this dialog exists to fix.
 *  - A REFUSED delete keeps the dialog open and reports why. A 403 on a
 *    delegated secret must not close the dialog and the sidebar behind it,
 *    because that reads exactly like a delete that succeeded.
 *  - While the request is in flight the dialog cannot be dismissed: the
 *    delete would still land, so a close would read as a cancel that never
 *    happened.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-delete-secret
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDeleteConfirmDialog from '../../src/dialogs/SecretDeleteConfirmDialog.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'

/**
 * Mount the dialog with the library chrome stubbed out.
 *
 * @param {object} propsData Extra props to merge over the defaults.
 * @return {object} The wrapper.
 */
function mountDialog(propsData = {}) {
	return mount(SecretDeleteConfirmDialog, {
		propsData: { secretId: 'secret-1', ...propsData },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcNoteCard: { template: '<div class="note"><slot /></div>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				NcLoadingIcon: true,
				TrashCanOutline: true,
			},
		},
	})
}

describe('SecretDeleteConfirmDialog', () => {
	let secretStore

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		secretStore = useSecretStore()
	})

	it('sends nothing until the confirm button is pressed', async () => {
		const deleteSecret = vi
			.spyOn(secretStore, 'deleteSecret')
			.mockResolvedValue(undefined)

		const wrapper = mountDialog()
		expect(deleteSecret).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="secret-delete-confirm"]').trigger('click')
		expect(deleteSecret).toHaveBeenCalledWith('secret-1')
	})

	it('reports the deletion to the host and closes', async () => {
		vi.spyOn(secretStore, 'deleteSecret').mockResolvedValue(undefined)
		const onDeleted = vi.fn()

		const wrapper = mountDialog({ onDeleted })
		await wrapper.find('[data-testid="secret-delete-confirm"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(onDeleted).toHaveBeenCalledWith('secret-1')
		expect(wrapper.emitted('deleted')).toEqual([['secret-1']])
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	// A refused delete that closed the dialog would be indistinguishable from
	// one that worked — the sidebar behind it would close too and the row
	// would still be in the list on the next load.
	it('stays open with the server reason when the delete is refused', async () => {
		vi.spyOn(secretStore, 'deleteSecret').mockRejectedValue({
			response: { status: 403, data: { message: 'Not the owner' } },
		})
		const onDeleted = vi.fn()

		const wrapper = mountDialog({ onDeleted })
		await wrapper.find('[data-testid="secret-delete-confirm"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(onDeleted).not.toHaveBeenCalled()
		expect(wrapper.emitted('deleted')).toBeFalsy()
		expect(wrapper.emitted('close')).toBeFalsy()
		expect(wrapper.text()).toContain('Not the owner')
	})

	it('closes without deleting when Cancel is pressed', async () => {
		const deleteSecret = vi.spyOn(secretStore, 'deleteSecret')
		const onDeleted = vi.fn()

		const wrapper = mountDialog({ onDeleted })
		await wrapper.find('[data-testid="secret-delete-cancel"]').trigger('click')

		expect(deleteSecret).not.toHaveBeenCalled()
		expect(onDeleted).not.toHaveBeenCalled()
		expect(wrapper.emitted('deleted')).toBeFalsy()
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	// Once the request is on the wire it will land regardless of what the
	// dialog does, so a dismissal mid-flight would look like a cancel that
	// actually deleted the secret.
	it('cannot be dismissed while the delete request is in flight', async () => {
		let resolveDelete
		vi.spyOn(secretStore, 'deleteSecret').mockImplementation(
			() =>
				new Promise((resolve) => {
					resolveDelete = resolve
				}),
		)

		const wrapper = mountDialog()
		await wrapper.find('[data-testid="secret-delete-confirm"]').trigger('click')

		const cancel = wrapper.find('[data-testid="secret-delete-cancel"]')
		expect(cancel.attributes('disabled')).toBeDefined()

		// NcDialog's other dismissal paths (Esc, the top-right close button)
		// arrive as `update:open` = false; the stub cannot produce them, so
		// exercise the handler directly.
		wrapper.vm.onUpdateOpen(false)
		expect(wrapper.emitted('close')).toBeFalsy()

		resolveDelete()
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('deleted')).toEqual([['secret-1']])
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('falls back to a generic failure message when the server sends none', async () => {
		vi.spyOn(secretStore, 'deleteSecret').mockRejectedValue(new Error('offline'))

		const wrapper = mountDialog()
		await wrapper.find('[data-testid="secret-delete-confirm"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Failed to delete secret')
		expect(wrapper.emitted('close')).toBeFalsy()
	})
})
