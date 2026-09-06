/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/MigrationResumeBanner.vue`.
 *
 * This banner is the only thing standing between a user and a silently
 * write-locked vault. If a rotation is interrupted — closed tab, crash, reload
 * — the migration row stays `in_progress`, the completion endpoint is never
 * reached, and nothing else in the UI mentions it. It MUST:
 *  - render nothing when there is no migration in progress
 *  - say how many records remain, from server-derived state
 *  - never claim zero remaining when the count could not be fetched
 *  - ask for the PREVIOUS master password only (the new key is in the session)
 *  - refuse to offer the form while the vault is locked
 *  - surface the acknowledgement case rather than leaving the banner unexplained
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MigrationResumeBanner from '../../src/components/MigrationResumeBanner.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useSessionStore } from '../../src/store/modules/session.js'

/**
 * Mount with the Nextcloud surface stubbed and interpolating t/n, since the
 * global stubs in tests/vitest/setup.js return keys verbatim.
 *
 * @return {object} The mounted wrapper.
 */
function mountBanner() {
	return mount(MigrationResumeBanner, {
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
				// Renders its label so the "which password are we asking for?"
				// assertion tests something real rather than a dropped prop.
				NcPasswordField: {
					props: ['label'],
					template: '<label><span>{{ label }}</span><input /></label>',
				},
				AlertOutline: true,
			},
		},
	})
}

describe('MigrationResumeBanner', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		// mounted() calls these; keep them inert unless a test says otherwise.
		const store = useEncryptionSuiteStore()
		vi.spyOn(store, 'fetchMigrationStatus').mockResolvedValue(undefined)
		vi.spyOn(store, 'fetchMigrationRemaining').mockResolvedValue(null)
	})

	it('renders nothing when no migration is in progress', async () => {
		const wrapper = mountBanner()
		await wrapper.vm.$nextTick()

		expect(
			wrapper.find('[data-testid="migration-resume-banner"]').exists(),
		).toBe(false)
	})

	it('states how many records remain and that the vault is read-only', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = {
			id: 'migration-1',
			oldSuiteId: 'old',
			newSuiteId: 'new',
		}
		store.migrationRemaining = 7

		const wrapper = mountBanner()
		await wrapper.vm.$nextTick()

		expect(
			wrapper.find('[data-testid="migration-resume-banner"]').exists(),
		).toBe(true)
		expect(wrapper.text()).toContain(
			'7 secrets are still encrypted under your previous key',
		)
		expect(wrapper.text()).toContain('read-only until it finishes')
	})

	it('does not claim zero remaining when the count is unknown', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationRemaining = null

		const wrapper = mountBanner()
		await wrapper.vm.$nextTick()

		const text = wrapper.text()
		// "0 secrets" would read as "finished", which is the opposite of the truth.
		expect(text).not.toContain('0 secrets')
		expect(text).toContain(
			'Some secrets are still encrypted under your previous key',
		)
	})

	it('asks for the previous master password, not both', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		const session = useSessionStore()
		session.cryptoKey = {}

		const wrapper = mountBanner()
		wrapper.vm.expanded = true
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Your previous master password')
		expect(wrapper.find('.keepiq-migration-banner__form').exists()).toBe(true)
	})

	it('withholds the form while the vault is locked', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		const session = useSessionStore()
		session.cryptoKey = null

		const wrapper = mountBanner()
		wrapper.vm.expanded = true
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.keepiq-migration-banner__form').exists()).toBe(false)
		expect(wrapper.text()).toContain('Unlock your vault first')
	})

	it('resumes with the supplied password and refreshes the count', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		const session = useSessionStore()
		session.cryptoKey = {}
		const resume = vi
			.spyOn(store, 'resumeMigration')
			.mockResolvedValue({ migrated: 7, failed: 0 })

		const wrapper = mountBanner()
		wrapper.vm.expanded = true
		wrapper.vm.oldPassword = 'previous-master-password'
		await wrapper.vm.onResume()

		expect(resume).toHaveBeenCalledWith('previous-master-password')
		expect(store.fetchMigrationRemaining).toHaveBeenCalled()
		expect(wrapper.vm.error).toBeNull()
		// The field must not keep holding the master password afterwards.
		expect(wrapper.vm.oldPassword).toBe('')
	})

	it('explains where the decision lives when a loss needs acknowledging', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		const session = useSessionStore()
		session.cryptoKey = {}
		vi.spyOn(store, 'resumeMigration').mockImplementation(async () => {
			store.migrationNeedsAcknowledgement = true
			store.migrationBlockedMessage =
				'2 secret(s) could not be decrypted with the old key'
			return { migrated: 5, failed: 2 }
		})

		const wrapper = mountBanner()
		wrapper.vm.expanded = true
		wrapper.vm.oldPassword = 'previous-master-password'
		await wrapper.vm.onResume()

		expect(wrapper.vm.error).toContain('could not be decrypted with the old key')
	})

	it('surfaces a failed resume without losing the banner', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		const session = useSessionStore()
		session.cryptoKey = {}
		vi.spyOn(store, 'resumeMigration').mockRejectedValue(
			new Error(
				'Your session is unlocked against the previous encryption suite.',
			),
		)

		const wrapper = mountBanner()
		wrapper.vm.expanded = true
		wrapper.vm.oldPassword = 'wrong'
		await wrapper.vm.onResume()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.error).toContain('previous encryption suite')
		expect(
			wrapper.find('[data-testid="migration-resume-banner"]').exists(),
		).toBe(true)
	})
})
