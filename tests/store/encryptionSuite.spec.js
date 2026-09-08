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

/**
 * The share-candidate list behind the team-folder member picker.
 *
 * Holding an ACTIVE suite is what makes someone shareable at all — without one
 * there is no public key to encrypt a copy for — so this is the only membership
 * list worth offering. It reads that off whatever `GET /suites` returns, which
 * is why it needs no change on the day that endpoint stops answering for the
 * caller alone.
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
 */
describe('useEncryptionSuiteStore — suite owners', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		document.head.setAttribute('data-user', 'me')
	})

	it('is empty while the endpoint answers for the caller alone', async () => {
		// Today's server: EncryptionSuiteController::index passes the session
		// user to getSuitesByOwner, so every row is the caller's own. They are
		// not a candidate for their own team folder, which leaves nothing —
		// and callers fall back to asking for an id by hand rather than
		// showing a list of one useless entry.
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ ownerType: 'user', ownerId: 'me', status: 'active' },
				{ ownerType: 'user', ownerId: 'me', status: 'revoked' },
			],
		})

		await expect(useEncryptionSuiteStore().fetchSuiteOwners()).resolves.toEqual(
			[],
		)
	})

	it('lists the other active user owners once the endpoint reports them', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ ownerType: 'user', ownerId: 'carol', status: 'active' },
				{ ownerType: 'user', ownerId: 'alice', status: 'active' },
				// Duplicate owner: one candidate, not two.
				{ ownerType: 'user', ownerId: 'alice', status: 'active' },
				// Revoked — no key to encrypt to, so its copies could never
				// be created and the reconcile pass would report them missing
				// for good.
				{ ownerType: 'user', ownerId: 'dave', status: 'revoked' },
				// Not a user: a suite can be owned by other things.
				{ ownerType: 'group', ownerId: 'devops', status: 'active' },
				{ ownerType: 'user', ownerId: 'me', status: 'active' },
			],
		})

		const store = useEncryptionSuiteStore()
		await store.fetchSuiteOwners()

		expect(store.suiteOwners).toEqual(['alice', 'carol'])
	})

	it('survives a response that is not a list', async () => {
		// An error payload ({message: ...}) must not throw on iteration: the
		// picker is a convenience and its failure cannot take the dialog down.
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { message: 'nope' } })

		await expect(useEncryptionSuiteStore().fetchSuiteOwners()).resolves.toEqual(
			[],
		)
	})
})
