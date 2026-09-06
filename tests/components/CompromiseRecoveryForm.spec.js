/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/CompromiseRecoveryForm.vue`.
 *
 * This dialog is the only surface a user sees during compromise recovery, and
 * the spec constrains what it is allowed to say. It MUST:
 *  - state, before the user confirms, that every stored value must be changed
 *    at its source, and that rotating the key restores ACCESS rather than
 *    making anything safe
 *  - never claim the vault is secure afterwards
 *  - show live progress across all stores while running, and not report an
 *    outcome until the migration has actually terminated
 *  - name the secrets that would lose access, and only lock the old key from
 *    an explicit click that says how many are affected
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CompromiseRecoveryForm from '../../src/components/CompromiseRecoveryForm.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

/**
 * Mount the form with the Nextcloud component surface stubbed out, so the test
 * asserts on this component's own behaviour rather than on @nextcloud/vue.
 *
 * @return {object} The mounted wrapper.
 */
function mountForm() {
	return mount(CompromiseRecoveryForm, {
		global: {
			// tests/vitest/setup.js installs t/n stubs that return the key
			// verbatim, which is fine for most specs but hides the counts this
			// dialog is judged on. A mount-level mixin wins over the global one,
			// so interpolate here to assert on what the user actually reads.
			mixins: [
				{
					methods: {
						t: (app, text, vars) => {
							if (!vars) {
								return text
							}
							return Object.keys(vars).reduce(
								(out, key) =>
									out.replace(`{${key}}`, String(vars[key])),
								text,
							)
						},
						n: (app, singular, plural, count) =>
							(count === 1 ? singular : plural).replace(
								'%n',
								String(count),
							),
					},
				},
			],
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				NcNoteCard: { template: '<div class="note-card"><slot /></div>' },
				NcPasswordField: { template: '<input />' },
				NcProgressBar: { template: '<div class="progress-bar" />' },
				PasswordStrengthMeter: true,
			},
		},
	})
}

describe('CompromiseRecoveryForm', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('warns before confirming that values must be changed at their source', () => {
		const text = mountForm().text()

		expect(text).toContain('must be assumed to have been exposed')
		expect(text).toContain('changed at its source')
		// The distinction the spec insists on: access, not safety.
		expect(text).toContain('restores access')
		expect(text).toContain('does not make the old values safe')
	})

	it('never claims the vault is now secure', async () => {
		const wrapper = mountForm()
		wrapper.vm.phase = 'terminal'
		wrapper.vm.result = { migrated: 12, droppedVersions: 0, failures: [] }
		await wrapper.vm.$nextTick()

		const text = wrapper.text()
		expect(text).toContain('12 secrets were re-encrypted')
		expect(text).toContain('still to be considered exposed')
		// The message this change exists to delete.
		expect(text).not.toContain('now secured with a new encryption key')
		expect(text).not.toContain('vault is now secure')
	})

	it('reports dropped version history rather than losing it quietly', async () => {
		const wrapper = mountForm()
		wrapper.vm.phase = 'terminal'
		wrapper.vm.result = { migrated: 3, droppedVersions: 7, failures: [] }
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('7 older versions were dropped')
	})

	it('shows live progress across all stores while running', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationProgress = { done: 4, total: 10, phase: 'migrating' }

		const wrapper = mountForm()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('4 of 10 records re-encrypted')
		expect(wrapper.vm.progressPercent).toBe(40)
		expect(wrapper.find('.progress-bar').exists()).toBe(true)
	})

	it('does not present an outcome while an acknowledgement is pending', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationNeedsAcknowledgement = true
		store.migrationFailures = [
			{
				store: 'secrets',
				id: 'secret-1',
				name: 'router-admin',
				error: 'secrets: could not decrypt',
			},
		]

		const wrapper = mountForm()
		await wrapper.vm.$nextTick()

		// The migration is still in progress, so no terminal wording.
		expect(wrapper.vm.phase).not.toBe('terminal')
		expect(wrapper.text()).not.toContain('Key rotation finished')
	})

	it('names the secrets that would lose access and says the data is kept', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationNeedsAcknowledgement = true
		store.migrationFailures = [
			{
				store: 'secrets',
				id: 'secret-1',
				name: 'router-admin',
				error: 'secrets: could not decrypt',
			},
			{
				store: 'secrets',
				id: 'secret-2',
				name: 'nas-backup',
				error: 'secrets: could not decrypt',
			},
		]

		const wrapper = mountForm()
		await wrapper.vm.$nextTick()

		const text = wrapper.text()
		expect(text).toContain('router-admin')
		expect(text).toContain('nas-backup')
		expect(text).toContain('2 secrets could not be decrypted')
		// Locking the key is destructive of access, not of data — say so.
		expect(text).toContain('stored data is kept')
		// The destructive action must state its own cost.
		expect(text).toContain('losing access to 2 secrets')
		// And a non-destructive way out must be offered alongside it.
		expect(text).toContain('Try these again')
	})

	it("sends the SERVER's acknowledgement number, never its own list length", async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationNeedsAcknowledgement = true
		store.migrationFailures = [
			{
				store: 'secrets',
				id: 'secret-1',
				name: 'router-admin',
				error: 'secrets: could not decrypt',
			},
		]
		// The server said 4 — one head plus three of its versions, say — while
		// the client's own list holds 1 entry. Sending the list length was the
		// third blocker: the server compares with a strict `===`, so every
		// click was refused and the vault stayed write-locked with no way out.
		store.migrationRequiredAcknowledgement = 4

		const accept = vi.spyOn(store, 'acceptMigrationLosses').mockResolvedValue({})

		const wrapper = mountForm()
		await wrapper.vm.handleAcceptLosses()

		// Called with the id alone: the action reads the authoritative number
		// from the store rather than being handed a count derived here.
		expect(accept).toHaveBeenCalledWith('migration-1')
		expect(wrapper.vm.phase).toBe('terminal')
	})

	it("shows the server's loss count even when the display list is capped", async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationNeedsAcknowledgement = true
		store.migrationRequiredAcknowledgement = 512
		store.migrationFailures = [
			{ store: 'secrets', id: 'secret-1', name: 'router-admin', error: 'x' },
		]

		const wrapper = mountForm()

		// The count the user is asked to accept is the real one, not the
		// length of a list that the server caps for display.
		expect(wrapper.vm.lossCount).toBe(512)
		expect(wrapper.vm.lossListTruncated).toBe(true)
	})

	it('labels a version failure instead of rendering a blank row', () => {
		const wrapper = mountForm()

		// Version and grant failures carry no secret name; the list used to
		// render an empty bullet directly above the "losing access" button.
		expect(
			wrapper.vm.describeRecord({ id: 'v-1', name: null, store: 'versions' }),
		).toContain('v-1')
		expect(
			wrapper.vm.describeRecord({
				id: 's-1',
				name: 'router-admin',
				store: 'secrets',
			}),
		).toBe('router-admin')
	})

	it('surfaces a halted run as an error and returns to the form', async () => {
		const store = useEncryptionSuiteStore()
		vi.spyOn(store, 'initiateCompromiseRecovery').mockRejectedValue(
			new Error('Re-encrypted key did not decrypt back to the original value'),
		)

		const wrapper = mountForm()
		await wrapper.vm.handleSubmit()

		expect(wrapper.vm.error).toContain('did not decrypt back')
		expect(wrapper.vm.phase).toBe('idle')
	})
})
