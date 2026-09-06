/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Termination tests for the migration driver loop in
 * `src/store/modules/encryptionSuite.js`.
 *
 * WHY THIS FILE EXISTS.
 *
 * The loop re-derives its work list from the server on every pass, because that
 * is what makes a migration resumable after a closed tab. The consequence is
 * that a pass which commits nothing gets the SAME page back next time — so the
 * loop only terminates if it notices it made no progress.
 *
 * It did not. The original break condition was:
 *
 *     if (outcome.committed === 0 && outcome.failures.length === 0) { break }
 *
 * which only caught the all-transient case. When every record in a page failed
 * PERMANENTLY, `committed` was 0 but `failures` was not empty, so neither break
 * fired: the loop re-fetched the same rows, re-failed them, and span forever
 * with the dialog frozen at "0 of N records re-encrypted". A live Playwright run
 * against a vault of undecryptable rows hit exactly that and timed out at 180s
 * — no unit test had covered a page that cannot make progress.
 *
 * These tests pin both exits so the spin cannot come back.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

/** Stand-in keys: the pipeline is stubbed, so these are never used as keys. */
const KEYS = { oldPrivateKey: 'old', newPublicKey: 'pub', newPrivateKey: 'new' }

/**
 * A work page holding one secret, as GET .../work returns it.
 *
 * @param {number} remaining What the server reports as still outstanding.
 * @return {object} The response body.
 */
function workPage(remaining) {
	return {
		data: {
			migrationId: 'migration-1',
			secrets: {
				records: [{ id: 'secret-1', name: 'stuck', key: 'CIPHERTEXT' }],
				remaining,
			},
			versions: { records: [], remaining: 0, dropCandidates: 0 },
			attachmentGrants: { records: [], remaining: 0 },
			totalRemaining: remaining,
		},
	}
}

vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => ({ evict: async () => {} }),
}))

// The driver is stubbed so these tests exercise the LOOP, not WebCrypto. Each
// test installs its own per-record outcome via `nextResults`.
let nextResults = []
vi.mock('../../src/migration/driver.js', () => ({
	createMigrationRunner: async () => ({
		usesWorker: false,
		run: async () => nextResults,
		dispose: () => {},
	}),
}))

vi.mock('../../src/crypto/index.js', async (importOriginal) => ({
	...(await importOriginal()),
	decryptPrivateKey: async () =>
		'-----BEGIN PRIVATE KEY-----stub-----END PRIVATE KEY-----',
	importPrivateKey: async () => 'imported-old-key',
	importPublicKey: async () => 'imported-new-key',
}))

describe('migration driver loop — termination', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		nextResults = []
	})

	it('stops after a page where every record failed permanently', async () => {
		// The regression. The server keeps returning the row (it is still bound to
		// the old suite), so without a no-progress exit this never returns.
		nextResults = [
			{
				store: 'secrets',
				id: 'secret-1',
				ok: false,
				permanent: true,
				halt: false,
				phase: 'decrypt-old',
				error: 'Existing key could not be decrypted with the previous key',
			},
		]

		const get = vi.spyOn(axios, 'get').mockResolvedValue(workPage(1))
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { recorded: true } })

		const store = useEncryptionSuiteStore()
		const outcome = await store.runMigration({
			migrationId: 'migration-1',
			oldEncryptedPrivateKey: 'wrapped',
			oldPassword: 'previous',
			newPublicKeyPem: '-----BEGIN PUBLIC KEY-----x-----END PUBLIC KEY-----',
			newPrivateKey: KEYS.newPrivateKey,
		})

		expect(outcome.migrated).toBe(0)
		expect(outcome.failed).toBe(1)
		// One pass only: a second fetch would mean it went round again.
		expect(get).toHaveBeenCalledTimes(1)
		// The permanent failure IS reported, so the server can record it and the
		// completion path can offer the acknowledgement.
		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][1]).toHaveProperty('error')
	})

	it('halts, rather than reporting a loss, when a page is entirely transient', async () => {
		// Nothing committed and nothing permanent: the rows stay unaccounted
		// server-side so the gate refuses and the banner picks it up later.
		nextResults = [
			{
				store: 'secrets',
				id: 'secret-1',
				ok: false,
				permanent: false,
				halt: false,
				phase: 'unclassified',
				error: 'Network error',
			},
		]

		vi.spyOn(axios, 'get').mockResolvedValue(workPage(1))
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()

		await expect(
			store.runMigration({
				migrationId: 'migration-1',
				oldEncryptedPrivateKey: 'wrapped',
				oldPassword: 'previous',
				newPublicKeyPem:
					'-----BEGIN PUBLIC KEY-----x-----END PUBLIC KEY-----',
				newPrivateKey: KEYS.newPrivateKey,
			}),
		).rejects.toThrow(/could not reach the server/i)

		// A retryable failure must never be reported as a per-record error: doing
		// so would let the server finalise it as unrecoverable.
		expect(post).not.toHaveBeenCalled()
	})

	it('halts immediately on a round-trip mismatch and reports nothing', async () => {
		// The stored value decrypted fine, so it is readable and must not be
		// recorded as a loss; the new key is at fault and will fail every record.
		nextResults = [
			{
				store: 'secrets',
				id: 'secret-1',
				ok: false,
				permanent: false,
				halt: true,
				phase: 'verify',
				error: 'Re-encrypted key did not decrypt back to the original value',
			},
		]

		vi.spyOn(axios, 'get').mockResolvedValue(workPage(1))
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const store = useEncryptionSuiteStore()

		await expect(
			store.runMigration({
				migrationId: 'migration-1',
				oldEncryptedPrivateKey: 'wrapped',
				oldPassword: 'previous',
				newPublicKeyPem:
					'-----BEGIN PUBLIC KEY-----x-----END PUBLIC KEY-----',
				newPrivateKey: KEYS.newPrivateKey,
			}),
		).rejects.toThrow(/did not decrypt back/i)

		expect(post).not.toHaveBeenCalled()
	})

	it('stops when the server reports no work left', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				secrets: { records: [], remaining: 0 },
				versions: { records: [], remaining: 0, dropCandidates: 0 },
				attachmentGrants: { records: [], remaining: 0 },
				totalRemaining: 0,
			},
		})

		const store = useEncryptionSuiteStore()
		const outcome = await store.runMigration({
			migrationId: 'migration-1',
			oldEncryptedPrivateKey: 'wrapped',
			oldPassword: 'previous',
			newPublicKeyPem: '-----BEGIN PUBLIC KEY-----x-----END PUBLIC KEY-----',
			newPrivateKey: KEYS.newPrivateKey,
		})

		expect(outcome.migrated).toBe(0)
		expect(outcome.failed).toBe(0)
	})
})

describe('vault lock teardown', () => {
	/*
	 * src/migration/worker.js states "the store terminates this worker on vault
	 * lock, so no key reference survives a locked vault". `registerLockReset`
	 * implemented that, but nothing called it — so the claim was false and a
	 * worker holding structured-cloned CryptoKeys kept running through a session
	 * timeout or an explicit "Lock vault". A defined-but-uncalled teardown is
	 * identical to having none, so the wiring is asserted here.
	 */
	it('disposes the migration runner and clears state when the vault locks', () => {
		const store = useEncryptionSuiteStore()
		const dispose = vi.fn()

		store.migrationRunner = { dispose }
		store.migrationProgress = { done: 3, total: 9 }
		store.migrationFailures = [{ store: 'secrets', id: 's-1', error: 'x' }]
		store.migrationNeedsAcknowledgement = true
		store.migrationRequiredAcknowledgement = 2

		store.resetMigrationState()

		expect(dispose).toHaveBeenCalledTimes(1)
		expect(store.migrationRunner).toBeNull()
		expect(store.migrationProgress).toBeNull()
		expect(store.migrationFailures).toEqual([])
		expect(store.migrationNeedsAcknowledgement).toBe(false)
		expect(store.migrationRequiredAcknowledgement).toBeNull()
	})

	it('tears the worker down when the vault actually locks', async () => {
		const { useSessionStore } =
			await import('../../src/store/modules/session.js')
		const store = useEncryptionSuiteStore()
		const session = useSessionStore()
		const dispose = vi.fn()

		// Armed the way MigrationResumeBanner arms it on mount.
		store.registerLockReset()
		store.migrationRunner = { dispose }

		// A real lock, not a direct call to resetMigrationState: what the
		// concern was about is the wiring between the two, so the wiring is
		// what gets exercised.
		session.lock()

		expect(dispose).toHaveBeenCalledTimes(1)
		expect(store.migrationRunner).toBeNull()
	})
})
