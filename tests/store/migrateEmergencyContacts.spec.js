/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the emergency-access re-envelope step of compromise-recovery
 * rotation (migrate-emergency-access-on-rotation, tasks 2.x / 4.3 / 4.5).
 *
 * For each of the owner's non-invalidated contacts the browser fetches the
 * grantee's current certificate, builds a fresh envelope escrowing the NEW
 * private key, and posts it to the migration re-point endpoint. A grantee with
 * no reachable certificate, or a transient re-point failure, is not fatal: the
 * contact is left for the completion sweep and its grantee returned as residual.
 *
 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { buildRecoveryEnvelope } from '../../src/crypto/emergencyEnvelope.js'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => ({ evict: async () => {} }),
}))

vi.mock('../../src/crypto/emergencyEnvelope.js', () => ({
	buildRecoveryEnvelope: vi.fn(async () => 'FRESH-ENVELOPE-JSON'),
}))

/**
 * Mock axios.get so contacts and grantee-certificate resolve as configured.
 *
 * @param {object} opts The options.
 * @param {Array<object>} opts.contacts The owner's emergency contacts.
 * @param {object} opts.certs Map of granteeUserId -> {suiteId, certificate} or 'throw'.
 * @return {void}
 */
function mockGets({ contacts, certs }) {
	vi.spyOn(axios, 'get').mockImplementation(async (url, config) => {
		if (url.endsWith('/emergency-access/contacts')) {
			return { data: contacts }
		}
		if (url.endsWith('/emergency-access/grantee-certificate')) {
			const grantee = config?.params?.granteeUserId
			const cert = certs[grantee]
			if (cert === 'throw' || cert === undefined) {
				throw new Error('no active certificate')
			}
			return { data: cert }
		}
		return { data: {} }
	})
}

describe('useEncryptionSuiteStore — migrateEmergencyContacts', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		buildRecoveryEnvelope.mockClear()
		buildRecoveryEnvelope.mockResolvedValue('FRESH-ENVELOPE-JSON')
	})

	it('re-envelopes a reachable grantee and reports no residual', async () => {
		mockGets({
			contacts: [{ id: 'rel-1', granteeUserId: 'bob', state: 'granted' }],
			certs: { bob: { suiteId: 'bob-suite', certificate: 'BOB-CERT' } },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await store.migrateEmergencyContacts({
			migrationId: 'migr-1',
			newPrivateKeyPem: 'NEW-PEM',
		})

		expect(residual).toEqual([])
		expect(buildRecoveryEnvelope).toHaveBeenCalledWith('NEW-PEM', 'BOB-CERT')
		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/migrations/migr-1/emergency-contacts/rel-1'),
			{ recoveryEnvelope: 'FRESH-ENVELOPE-JSON', granteeSuiteId: 'bob-suite' },
		)
	})

	it('reports an unreachable grantee as residual and never posts', async () => {
		mockGets({
			contacts: [{ id: 'rel-1', granteeUserId: 'bob', state: 'granted' }],
			certs: { bob: 'throw' },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await store.migrateEmergencyContacts({
			migrationId: 'migr-1',
			newPrivateKeyPem: 'NEW-PEM',
		})

		expect(residual).toEqual(['bob'])
		expect(post).not.toHaveBeenCalled()
	})

	it('skips an already-invalidated contact entirely', async () => {
		mockGets({
			contacts: [
				{ id: 'rel-x', granteeUserId: 'carol', state: 'invalidated' },
			],
			certs: {},
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await store.migrateEmergencyContacts({
			migrationId: 'migr-1',
			newPrivateKeyPem: 'NEW-PEM',
		})

		expect(residual).toEqual([])
		expect(buildRecoveryEnvelope).not.toHaveBeenCalled()
		expect(post).not.toHaveBeenCalled()
	})

	it('treats a re-point failure as residual without halting the run', async () => {
		mockGets({
			contacts: [
				{ id: 'rel-1', granteeUserId: 'bob', state: 'granted' },
				{ id: 'rel-2', granteeUserId: 'dave', state: 'granted' },
			],
			certs: {
				bob: { suiteId: 'bob-suite', certificate: 'BOB-CERT' },
				dave: { suiteId: 'dave-suite', certificate: 'DAVE-CERT' },
			},
		})
		// bob's re-point fails; dave's succeeds — only bob is residual.
		vi.spyOn(axios, 'post').mockImplementation(async (url) => {
			if (url.includes('/emergency-contacts/rel-1')) {
				throw new Error('transient')
			}
			return { data: {} }
		})

		const store = useEncryptionSuiteStore()
		const residual = await store.migrateEmergencyContacts({
			migrationId: 'migr-1',
			newPrivateKeyPem: 'NEW-PEM',
		})

		expect(residual).toEqual(['bob'])
	})

	it('returns no residual when the contacts cannot be enumerated', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('index unavailable'))
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()
		const residual = await store.migrateEmergencyContacts({
			migrationId: 'migr-1',
			newPrivateKeyPem: 'NEW-PEM',
		})

		expect(residual).toEqual([])
		expect(post).not.toHaveBeenCalled()
	})
})
