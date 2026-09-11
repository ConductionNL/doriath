/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the guarded revoke flow in `src/views/EmergencyAccessView.vue`.
 *
 * Deleting an emergency contact destroys its recovery envelope — the one
 * break-glass path that survives a private-key overwrite — so it is guarded by a
 * vault-key proof and MUST NOT happen from a click alone. This asserts the
 * confirm-dialog gate:
 *  - clicking "Revoke" opens the confirmation and does NOT call the store;
 *  - confirming passes the entered master password through to `store.revoke`,
 *    which is what builds the proof;
 *  - a guard refusal is surfaced and the dialog stays open to retry;
 *  - a successful revoke closes the dialog.
 *
 * @spec openspec/changes/harden-vault-key-material-guards/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EmergencyAccessView from '../../src/views/EmergencyAccessView.vue'
import { useEmergencyAccessStore } from '../../src/store/modules/emergencyAccess.js'

/**
 * Mount the view with the Nextcloud component surface stubbed, a store whose
 * network calls are inert, and one designated contact to act on.
 *
 * @return {Promise<{wrapper: object, store: object}>} The wrapper and store.
 */
async function mountView() {
	const store = useEmergencyAccessStore()
	vi.spyOn(store, 'fetchContacts').mockResolvedValue()
	vi.spyOn(store, 'fetchIncoming').mockResolvedValue()
	store.contacts = [
		{ id: 'rel-1', granteeUserId: 'bob', state: 'granted', waitPeriodDays: 7 },
	]
	store.incoming = []

	const wrapper = mount(EmergencyAccessView, {
		global: {
			mixins: [
				{
					methods: {
						t: (app, text, vars) =>
							vars
								? Object.keys(vars).reduce(
										(out, k) =>
											out.replace(`{${k}}`, String(vars[k])),
										text,
									)
								: text,
						n: (app, s, p, count) =>
							(count === 1 ? s : p).replace('%n', String(count)),
					},
				},
			],
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcPasswordField: { template: '<input />' },
				NcTextField: { template: '<input />' },
				NcSelect: { template: '<div />' },
				NcEmptyContent: { template: '<div />' },
				// Render the dialog's default + actions slots so the gate is visible.
				NcDialog: {
					props: ['open'],
					template:
						'<div v-if="open" class="nc-dialog"><slot /><slot name="actions" /></div>',
				},
			},
		},
	})
	await wrapper.vm.$nextTick()
	return { wrapper, store }
}

describe('EmergencyAccessView — guarded revoke', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('opens the confirm dialog on Revoke and does not call the store yet', async () => {
		const { wrapper, store } = await mountView()
		const revoke = vi.spyOn(store, 'revoke').mockResolvedValue()

		wrapper.vm.promptRevoke('rel-1')

		expect(wrapper.vm.revokeTarget).toBe('rel-1')
		expect(revoke).not.toHaveBeenCalled()
	})

	it('passes the entered master password to store.revoke on confirm', async () => {
		const { wrapper, store } = await mountView()
		const revoke = vi.spyOn(store, 'revoke').mockResolvedValue()

		wrapper.vm.promptRevoke('rel-1')
		wrapper.vm.revokePassword = 'master-pw'
		await wrapper.vm.confirmRevoke()

		expect(revoke).toHaveBeenCalledWith('rel-1', 'master-pw')
		// The dialog closes on success.
		expect(wrapper.vm.revokeTarget).toBe(null)
	})

	it('surfaces a guard refusal and keeps the dialog open to retry', async () => {
		const { wrapper, store } = await mountView()
		vi.spyOn(store, 'revoke').mockRejectedValue({
			response: {
				data: { error: 'key_proof_required', message: 'Wrong password' },
			},
		})

		wrapper.vm.promptRevoke('rel-1')
		wrapper.vm.revokePassword = 'wrong'
		await wrapper.vm.confirmRevoke()

		expect(wrapper.vm.revokeError).toContain('Wrong password')
		// Still open: the user can re-enter and try again.
		expect(wrapper.vm.revokeTarget).toBe('rel-1')
	})
})
