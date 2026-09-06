/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the worker-vs-main-thread selection in `src/migration/driver.js`.
 *
 * WHY THIS FILE EXISTS.
 *
 * design.md Decision 2 calls the main-thread fallback REQUIRED: `CryptoKey`
 * structured-cloning into a worker has historically been unreliable across
 * engines, so the driver must feature-detect by attempting the handshake and
 * fall back when it fails. Everything else in this change was covered, but the
 * fallback itself had never executed anywhere — the unit tests mock the driver
 * away, and the live Playwright run took whichever path Chromium supports, which
 * is the worker. So the safety net for the engines that need it most was
 * shipping unexercised.
 *
 * These tests drive all three selection outcomes: no Worker constructor at all,
 * a Worker whose postMessage rejects the CryptoKey clone (DataCloneError), and a
 * Worker that handshakes successfully. The pipeline is stubbed, because what is
 * under test is the SELECTION, not the crypto.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The pipeline is the shared module both paths import; stub it so the inline
// path is observable without doing RSA work.
const migrateRecord = vi.fn(async (job) => ({
	store: job.store,
	id: job.id,
	ok: true,
	payload: {},
}))
vi.mock('../../src/migration/pipeline.js', () => ({
	migrateRecord: (...args) => migrateRecord(...args),
	MIGRATION_STORES: {
		SECRETS: 'secrets',
		VERSIONS: 'versions',
		ATTACHMENT_GRANTS: 'attachmentGrants',
	},
}))

const { createMigrationRunner } = await import('../../src/migration/driver.js')

const KEYS = { oldPrivateKey: 'old', newPublicKey: 'pub', newPrivateKey: 'new' }
const JOBS = [
	{ store: 'secrets', id: 's-1', record: { key: 'C1' } },
	{ store: 'secrets', id: 's-2', record: { key: 'C2' } },
]

/** Whatever `Worker` was before a test replaced it. */
let originalWorker

beforeEach(() => {
	originalWorker = globalThis.Worker
	migrateRecord.mockClear()
})

afterEach(() => {
	if (originalWorker === undefined) {
		delete globalThis.Worker
	} else {
		globalThis.Worker = originalWorker
	}
})

describe('createMigrationRunner — path selection', () => {
	it('falls back inline when the environment has no Worker at all', async () => {
		delete globalThis.Worker

		const runner = await createMigrationRunner(KEYS)

		expect(runner.usesWorker).toBe(false)
		const results = await runner.run(JOBS)
		expect(results).toHaveLength(2)
		// The SAME pipeline module runs on both paths, which is what stops the
		// verification guarantees drifting between them.
		expect(migrateRecord).toHaveBeenCalledTimes(2)
		expect(migrateRecord.mock.calls[0][1]).toBe(KEYS)
		runner.dispose()
	})

	it('falls back inline when the CryptoKey clone is rejected (DataCloneError)', async () => {
		// The historical failure this fallback exists for: the Worker constructs,
		// but postMessage refuses to structured-clone the keys.
		const terminate = vi.fn()
		globalThis.Worker = class {
			constructor() {
				this.terminate = terminate
			}

			addEventListener() {}
			removeEventListener() {}
			postMessage() {
				throw new DOMException('Failed to clone CryptoKey', 'DataCloneError')
			}
		}

		const runner = await createMigrationRunner(KEYS)

		expect(runner.usesWorker).toBe(false)
		// The half-started worker must not be left running with key references.
		expect(terminate).toHaveBeenCalled()

		const results = await runner.run(JOBS)
		expect(results).toHaveLength(2)
		expect(migrateRecord).toHaveBeenCalledTimes(2)
	})

	it('falls back inline when the worker never answers the handshake', async () => {
		// A worker that constructs and accepts postMessage but stays silent must
		// not hang the migration behind an unresolved promise.
		const terminate = vi.fn()
		globalThis.Worker = class {
			constructor() {
				this.terminate = terminate
			}

			addEventListener() {}
			removeEventListener() {}
			postMessage() {
				// Silence.
			}
		}

		vi.useFakeTimers()
		try {
			const pending = createMigrationRunner(KEYS)
			await vi.advanceTimersByTimeAsync(6000)
			const runner = await pending

			expect(runner.usesWorker).toBe(false)
			expect(terminate).toHaveBeenCalled()
		} finally {
			vi.useRealTimers()
		}
	})

	it('uses the worker when the handshake succeeds, and posts the keys once', async () => {
		const posted = []
		const terminate = vi.fn()
		globalThis.Worker = class {
			constructor() {
				this.terminate = terminate
				this.listeners = {}
			}

			addEventListener(type, fn) {
				this.listeners[type] = [...(this.listeners[type] || []), fn]
			}

			removeEventListener(type, fn) {
				this.listeners[type] = (this.listeners[type] || []).filter(
					(f) => f !== fn,
				)
			}

			postMessage(msg) {
				posted.push(msg)
				const reply =
					msg.type === 'init'
						? { requestId: msg.requestId, type: 'ready', ok: true }
						: {
								requestId: msg.requestId,
								type: 'batch',
								ok: true,
								results: msg.jobs.map((j) => ({
									store: j.store,
									id: j.id,
									ok: true,
									payload: {},
								})),
							}
				// Deliver asynchronously, as a real worker would.
				setTimeout(() => {
					for (const fn of this.listeners.message || []) {
						fn({ data: reply })
					}
				}, 0)
			}
		}

		const runner = await createMigrationRunner(KEYS)
		expect(runner.usesWorker).toBe(true)

		const results = await runner.run(JOBS)
		expect(results).toHaveLength(2)

		// The keys are handed over exactly once, at init — not per batch.
		const inits = posted.filter((m) => m.type === 'init')
		expect(inits).toHaveLength(1)
		expect(inits[0]).toMatchObject({
			oldPrivateKey: 'old',
			newPublicKey: 'pub',
			newPrivateKey: 'new',
		})
		// And on the worker path the pipeline runs inside the worker, so the
		// main-thread stub must not have been touched.
		expect(migrateRecord).not.toHaveBeenCalled()

		runner.dispose()
		expect(terminate).toHaveBeenCalled()
	})
})
