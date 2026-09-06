/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for suite revocation in `useEncryptionSuiteStore`
 * (`src/store/modules/encryptionSuite.js`).
 *
 * WHY THIS FILE EXISTS.
 *
 * openspec/specs/encryption-suites/spec.md "Scenario: Revoke suite" was waived
 * with "No suite-revocation UI is built in v0.1; revocation is an API-only
 * action verified by PHPUnit and the Postman collection." Two of those three
 * claims were false when checked against the tree:
 *
 *   - The UI IS built and shipping. src/App.vue renders a "Revoke encryption
 *     suite" button, a confirmation NcNoteCard, and a reason field whose value
 *     gates the submit button (`:disabled="!revokeReason || revoking"`).
 *   - The Postman collection does NOT verify it. The only occurrence of
 *     "revoke" in tests/integration/keepiq.postman_collection.json is inside
 *     an error-message string; there is no revoke request at all.
 *
 * Only the PHPUnit half held (EncryptionSuiteServiceTest::testRevokeSuiteSuccess
 * and EncryptionSuiteControllerTest::testRevokeReturnsSuite).
 *
 * So the client half of a destructive, shipping flow had no test anywhere.
 * These tests cover it at the store boundary, which is where the security
 * invariant lives: revoking must not leave a readable offline copy behind.
 * That eviction is the kind of defect no API-shape assertion can see — the
 * HTTP call succeeds identically whether or not the local cache is cleared,
 * and the leftover plaintext would sit in the browser indefinitely.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-revocation
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

const evict = vi.fn(async () => {})

vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => ({ evict }),
}))

describe('useEncryptionSuiteStore — revocation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		evict.mockClear()
	})

	it('POSTs the revocation to the active suite with the supplied reason', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				id: 'suite-1',
				status: 'revoked',
				revoked_reason: 'laptop stolen',
			},
		})
		const store = useEncryptionSuiteStore()
		store.currentSuite = { id: 'suite-1', status: 'active' }

		await store.revokeSuite('laptop stolen')

		expect(post).toHaveBeenCalledTimes(1)
		const [url, body] = post.mock.calls[0]

		// The suite id must be in the path — revoking the wrong suite, or a
		// path built from a stale id, locks a user out of the wrong vault.
		expect(url).toContain('/apps/keepiq/api/v1/suites/suite-1/revoke')

		// The reason is REQUIRED by the spec: status is set alongside
		// revoked_at, revoked_reason and revoked_by. Dropping it here would
		// still return 200 and still revoke, losing only the audit trail —
		// which is exactly why it needs asserting rather than eyeballing.
		expect(body).toEqual({ reason: 'laptop stolen' })
	})

	it('adopts the revoked suite returned by the server as the current suite', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'suite-1', status: 'revoked', revoked_reason: 'rotation' },
		})
		const store = useEncryptionSuiteStore()
		store.currentSuite = { id: 'suite-1', status: 'active' }

		await store.revokeSuite('rotation')

		// Keeping the pre-revocation object would leave the UI showing an
		// active suite that the server has already revoked.
		expect(store.currentSuite.status).toBe('revoked')
	})

	it('evicts the offline cache so a revoked suite leaves no readable copy', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'suite-1', status: 'revoked' },
		})
		const store = useEncryptionSuiteStore()
		store.currentSuite = { id: 'suite-1', status: 'active' }

		await store.revokeSuite('compromised device')

		// THE SECURITY INVARIANT. Revocation blocks server-side access, but an
		// offline copy is already decrypted on this device: without the evict
		// the secrets stay readable locally after the user has been told they
		// are inaccessible.
		expect(evict).toHaveBeenCalledTimes(1)
	})

	it('still completes when no offline cache is present', async () => {
		evict.mockRejectedValueOnce(new Error('no cache'))
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'suite-1', status: 'revoked' },
		})
		const store = useEncryptionSuiteStore()
		store.currentSuite = { id: 'suite-1', status: 'active' }

		// A missing cache must not turn a completed server-side revocation into
		// a client-side error — the suite IS revoked by this point.
		await expect(store.revokeSuite('reason')).resolves.toBeUndefined()
		expect(store.currentSuite.status).toBe('revoked')
	})

	it('refuses to revoke when there is no active suite', async () => {
		const post = vi.spyOn(axios, 'post')
		const store = useEncryptionSuiteStore()
		store.currentSuite = null

		await expect(store.revokeSuite('reason')).rejects.toThrow(/No active suite/)

		// The guard must fire BEFORE any request: a POST built from a null
		// suite would target .../suites/undefined/revoke.
		expect(post).not.toHaveBeenCalled()
	})
})
