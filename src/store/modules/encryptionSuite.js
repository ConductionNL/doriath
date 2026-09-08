import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import {
	decryptPrivateKey,
	encryptPrivateKey,
	generateKeyPair,
	importPrivateKey,
	importPublicKey,
} from '../../crypto/index.js'
import { createMigrationRunner } from '../../migration/driver.js'
import { MIGRATION_STORES } from '../../migration/pipeline.js'
import { onVaultLock, useSessionStore } from './session.js'

/**
 * How many re-encryption POSTs are in flight at once. Small on purpose: the
 * point is to hide network latency, not to flood a shared instance. Provisional
 * — untuned against a large seeded vault.
 *
 * @type {number}
 */
const MIGRATION_CONCURRENCY = 4

export const useEncryptionSuiteStore = defineStore('encryptionSuite', {
	state: () => ({
		/** @type {object|null} Current active suite */
		currentSuite: null,
		/** @type {object|null} Migration status */
		migrationStatus: null,
		/** @type {boolean} */
		loading: false,
		/** @type {{done: number, total: number, phase: string}|null} Live migration progress */
		migrationProgress: null,
		/** @type {Array<{store: string, id: string, name: string|null, error: string}>} Per-record failures */
		migrationFailures: [],
		/** @type {number} Version rows dropped for falling outside the window */
		migrationDroppedVersions: 0,
		/** @type {Array<{id: string, name: string, error: string}>} Secrets that lost access */
		migrationUnrecoverable: [],
		/** @type {number|null} Records still on the old suite, for the resume banner */
		migrationRemaining: null,
		/** @type {boolean} Server wants the loss acknowledged before finalising */
		migrationNeedsAcknowledgement: false,
		/**
		 * The number the server will accept as the acknowledgement.
		 *
		 * NEVER derived from `migrationFailures.length`. That list holds one
		 * entry per failed RECORD accumulated across every pass of the loop,
		 * while the server counts the distinct records currently recorded as
		 * failed — and it compares with a strict `===`. The two agreed only
		 * when every failure was exactly one secret head failing exactly once,
		 * so any run where a secret failed alongside its own version, or the
		 * same record failed twice, made "Finish anyway" permanently
		 * unsatisfiable and left the vault write-locked.
		 *
		 * @type {number|null}
		 */
		migrationRequiredAcknowledgement: null,
		/** @type {string|null} Why the server refused to finalise */
		migrationBlockedMessage: null,
		/** @type {object|null} The active runner, so vault lock can dispose it */
		migrationRunner: null,
	}),

	actions: {
		/**
		 * Fetch the current user's active suite.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async fetchSuite() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/suites'),
				)
				const suites = response.data
				this.currentSuite = suites.find((s) => s.status === 'active') || null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new EncryptionSuite (first-time setup).
		 *
		 * @param {string} masterPassword
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async createSuite(masterPassword) {
			this.loading = true
			try {
				const { publicKeyPem, privateKey } = await generateKeyPair()

				// Export private key as PEM for encryption.
				const pkcs8 = await crypto.subtle.exportKey('pkcs8', privateKey)
				const privateKeyPem =
					'-----BEGIN PRIVATE KEY-----\n'
					+ btoa(String.fromCharCode(...new Uint8Array(pkcs8)))
						.match(/.{1,64}/g)
						.join('\n')
					+ '\n-----END PRIVATE KEY-----'

				const encryptedPk = await encryptPrivateKey(
					privateKeyPem,
					masterPassword,
				)

				const response = await axios.post(
					generateUrl('/apps/keepiq/api/v1/suites'),
					{
						publicKey: publicKeyPem,
						encryptedPrivateKey: encryptedPk,
					},
				)

				this.currentSuite = response.data

				// Immediately unlock the session.
				const session = useSessionStore()
				const cryptoKey = await importPrivateKey(privateKeyPem)
				session.cryptoKey = cryptoKey
				session.encryptedPrivateKey = encryptedPk
				session.certificate = response.data.certificate
				session.suiteId = response.data.id
				session.lastActivity = Date.now()
			} finally {
				this.loading = false
			}
		},

		/**
		 * Change master password (routine — re-wrap private key only).
		 *
		 * @param {string} oldPassword
		 * @param {string} newPassword
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async changePassword(oldPassword, newPassword) {
			const session = useSessionStore()

			// Decrypt private key with old password.
			const privateKeyPem = await decryptPrivateKey(
				session.encryptedPrivateKey,
				oldPassword,
			)

			// Re-encrypt with new password.
			const newEncryptedPk = await encryptPrivateKey(
				privateKeyPem,
				newPassword,
			)

			// Update on server.
			await axios.put(
				generateUrl(
					`/apps/keepiq/api/v1/suites/${session.suiteId}/private-key`,
				),
				{ encryptedPrivateKey: newEncryptedPk },
			)

			session.encryptedPrivateKey = newEncryptedPk
		},

		/**
		 * Initiate compromise recovery and run the migration to completion.
		 *
		 * Rotates the key pair, then actually migrates the vault: every secret,
		 * every in-window version and every attachment grant the user holds is
		 * decrypted with the old private key, re-encrypted under the new one,
		 * verified by decrypting it back, and committed one record per request.
		 * Only when nothing remains bound to the old suite is the migration
		 * completed — the server refuses earlier.
		 *
		 * The old suite stays `active` throughout, so the vault stays readable;
		 * this is what makes the migration possible at all, since it has to read
		 * the old ciphertext.
		 *
		 * @param {string} oldPassword The current master password.
		 * @param {string} newPassword The new master password.
		 * @return {Promise<{migrated: number, failed: number, droppedVersions: number,
		 *   failures: Array<object>, usedWorker: boolean}>} The migration outcome.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		async initiateCompromiseRecovery(oldPassword, newPassword) {
			const { publicKeyPem, privateKey } = await generateKeyPair()

			// Export new private key as PEM.
			const pkcs8 = await crypto.subtle.exportKey('pkcs8', privateKey)
			const newPrivateKeyPem =
				'-----BEGIN PRIVATE KEY-----\n'
				+ btoa(String.fromCharCode(...new Uint8Array(pkcs8)))
					.match(/.{1,64}/g)
					.join('\n')
				+ '\n-----END PRIVATE KEY-----'

			const newEncryptedPk = await encryptPrivateKey(
				newPrivateKeyPem,
				newPassword,
			)

			this.migrationProgress = { done: 0, total: 0, phase: 'starting' }
			this.migrationFailures = []
			this.migrationDroppedVersions = 0

			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/suites/compromise-recovery'),
				{
					publicKey: publicKeyPem,
					encryptedPrivateKey: newEncryptedPk,
				},
			)

			this.migrationStatus = response.data.migration
			this.currentSuite = response.data.newSuite

			// Rebind the session to the NEW suite, the way createSuite does.
			// Without this the session still names the old suite, and
			// resumeMigration's "is this session unlocked against the
			// migration's new suite?" check refused — so "Try these again", the
			// only in-dialog escape from a partly-failed run, raised an error
			// every time and pushed the user at "Finish anyway" instead.
			//
			// The key material is the pair generated above, so this is the same
			// binding createSuite performs on first-time setup, not a
			// re-derivation from anything the server sent.
			const session = useSessionStore()
			session.cryptoKey = await importPrivateKey(newPrivateKeyPem)
			session.encryptedPrivateKey = newEncryptedPk
			session.certificate = response.data.newSuite?.certificate ?? null
			session.suiteId = response.data.newSuite?.id ?? session.suiteId
			session.lastActivity = Date.now()

			// Suite rotation invalidates every cached ciphertext (encrypted to
			// the now-dead key) — evict the offline snapshot (offline-readonly-
			// cache §D4). Lazy import avoids a static store cycle.
			try {
				const { useOfflineStore } = await import('./offline.js')
				await useOfflineStore().evict()
			} catch (e) {
				// Offline cache absent — nothing to evict.
			}

			const outcome = await this.runMigration({
				migrationId: response.data.migration.id,
				oldEncryptedPrivateKey: response.data.oldEncryptedPrivateKey,
				oldPassword,
				newPublicKeyPem: publicKeyPem,
				// Re-imported rather than reusing the generated CryptoKey, so the
				// verification key is non-extractable like every other key here.
				newPrivateKey: await importPrivateKey(newPrivateKeyPem),
			})

			// Only now, with nothing left on the old suite, is the vault ready
			// for the terminal step. The premature complete() that used to sit
			// here reported success five lines after initiating, before a single
			// record had been touched.
			await this.finaliseMigration(response.data.migration.id, outcome)

			return outcome
		},

		/**
		 * Drive the re-encryption loop until no work remains.
		 *
		 * Resumable by construction: the work list is re-derived server-side from
		 * the rows themselves on every pass, so a run that resumes after a closed
		 * tab picks up exactly the records that did not make it.
		 *
		 * @param {object} params The migration parameters.
		 * @param {string} params.migrationId The migration ID.
		 * @param {string} params.oldEncryptedPrivateKey The AES-wrapped old private key.
		 * @param {string} params.oldPassword The old master password.
		 * @param {string} params.newPublicKeyPem The new public key or certificate, PEM.
		 * @param {CryptoKey} params.newPrivateKey The new private key, for verification.
		 * @return {Promise<{migrated: number, failed: number, droppedVersions: number,
		 *   failures: Array<object>, usedWorker: boolean}>} The migration outcome.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		async runMigration({
			migrationId,
			oldEncryptedPrivateKey,
			oldPassword,
			newPublicKeyPem,
			newPrivateKey,
		}) {
			// Unwrap the old private key with the old master password. Both stay
			// in this scope; neither is ever sent anywhere (ADR-003).
			const oldPrivateKeyPem = await decryptPrivateKey(
				oldEncryptedPrivateKey,
				oldPassword,
			)

			const keys = {
				oldPrivateKey: await importPrivateKey(oldPrivateKeyPem),
				newPublicKey: await importPublicKey(newPublicKeyPem),
				newPrivateKey,
			}

			const runner = await createMigrationRunner(keys)
			this.migrationRunner = runner

			let migrated = 0
			let halted = null
			const failures = []

			try {
				// Each pass re-asks for outstanding work from offset 0: committed
				// records leave the result set, so the next page is always at the
				// front.
				for (;;) {
					const { data } = await axios.get(
						generateUrl(
							`/apps/keepiq/api/v1/migrations/${migrationId}/work`,
						),
					)

					this.migrationDroppedVersions =
						data.versions?.dropCandidates || 0

					const jobs = this.buildJobs(data)
					if (jobs.length === 0) {
						break
					}

					// `totalRemaining` counts EVERY row still on the old suite,
					// including the version rows outside the retention window.
					// Those are reported as dropCandidates and never dispatched
					// as jobs — the server deletes them at completion — so using
					// the raw number as the denominator left the bar short of
					// 100% and then jumping to the terminal panel, which reads as
					// a hang during an already-anxious operation.
					const denominator =
						migrated
						+ Math.max(
							0,
							data.totalRemaining
								- (data.versions?.dropCandidates || 0),
						)

					this.migrationProgress = {
						done: migrated,
						total: denominator,
						phase: 'migrating',
					}

					const results = await runner.run(jobs)
					const outcome = await this.postResults(
						migrationId,
						results,
						jobs,
					)

					migrated += outcome.committed
					failures.push(...outcome.failures)
					this.migrationFailures = [...failures]

					this.migrationProgress = {
						done: migrated,
						total: denominator,
						phase: 'migrating',
					}

					// A round-trip that does not verify is not this record's
					// problem — it is the new key's. Stop immediately rather than
					// grinding through the vault producing ciphertext nobody can
					// read. Everything committed so far was individually verified,
					// so it stays valid, and the migration stays resumable.
					if (outcome.halt !== null) {
						halted = outcome.halt
						break
					}

					// A pass that commits nothing cannot make progress, and the
					// work list is re-derived from the rows — so asking again
					// returns exactly the same page. Stop either way; what
					// differs is whether it counts as a halt.
					if (outcome.committed === 0) {
						if (outcome.failures.length === 0) {
							// Nothing committed and nothing permanently failed:
							// the whole page was transient (offline, server
							// down). The rows stay unaccounted server-side, so
							// the completion gate will refuse and the resume
							// banner picks it up.
							halted =
								'Migration could not reach the server; no records were changed.'
						}

						// Otherwise every record in this page failed permanently
						// and is now recorded against its owning secret. Letting
						// the loop continue here was an infinite spin: the rows
						// stay bound to the old suite, so each pass re-fetched
						// and re-failed the same page and the dialog sat at
						// "0 of N" forever. Fall through to completion, which
						// asks the user what to do about them.
						break
					}
				}
			} finally {
				runner.dispose()
				this.migrationRunner = null
			}

			if (halted !== null) {
				this.migrationProgress = {
					done: migrated,
					total: migrated,
					phase: 'halted',
				}
				const error = new Error(halted)
				error.name = 'MigrationHaltedError'
				error.migrated = migrated
				error.failures = failures
				throw error
			}

			this.migrationProgress = {
				done: migrated,
				total: migrated,
				phase: 'done',
			}

			return {
				migrated,
				failed: failures.length,
				droppedVersions: this.migrationDroppedVersions,
				failures,
				usedWorker: runner.usesWorker,
			}
		},

		/**
		 * Flatten one work response into per-record jobs.
		 *
		 * @param {object} work The GET /work response body.
		 * @return {Array<object>} The jobs for this page.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		buildJobs(work) {
			const jobs = []

			for (const record of work.secrets?.records || []) {
				jobs.push({
					store: MIGRATION_STORES.SECRETS,
					id: record.id,
					name: record.name,
					record,
				})
			}
			for (const record of work.versions?.records || []) {
				jobs.push({
					store: MIGRATION_STORES.VERSIONS,
					id: record.id,
					name: null,
					record,
				})
			}
			for (const record of work.attachmentGrants?.records || []) {
				jobs.push({
					store: MIGRATION_STORES.ATTACHMENT_GRANTS,
					id: record.id,
					name: null,
					record,
				})
			}

			return jobs
		},

		/**
		 * POST one page of pipeline results, at most MIGRATION_CONCURRENCY at once.
		 *
		 * A record that failed its round-trip is reported to the server as an
		 * `error` so it lands in `migration_error` on the owning secret; its
		 * original ciphertext is left untouched and the run continues.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {Array<object>} results The pipeline results.
		 * @param {Array<object>} jobs The jobs the results correspond to.
		 * @return {Promise<{committed: number, failures: Array<object>}>} The page outcome.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
		 */
		async postResults(migrationId, results, jobs) {
			const namesById = new Map(jobs.map((job) => [job.id, job.name]))
			let committed = 0
			const failures = []
			const retryable = []
			let halt = null

			for (
				let start = 0;
				start < results.length;
				start += MIGRATION_CONCURRENCY
			) {
				const window = results.slice(start, start + MIGRATION_CONCURRENCY)

				const settled = await Promise.all(
					window.map(async (result) => {
						// A round-trip mismatch means the STORED value read back fine
						// and the new key could not carry it. Reporting that as a
						// failure would let the server finalise a readable secret as
						// unrecoverable, so it is never sent — the run stops instead.
						if (result.halt === true) {
							return { outcome: 'halt', result, error: result.error }
						}

						// Only an old-key decrypt failure is reported. Anything else
						// unproven stays unreported, which leaves the row unaccounted
						// server-side so the completion gate keeps blocking and a
						// resume retries it.
						if (result.ok !== true && result.permanent !== true) {
							return {
								outcome: 'retryable',
								result,
								error: result.error,
							}
						}

						const url = this.recordUrl(
							migrationId,
							result.store,
							result.id,
						)
						if (url === null) {
							return {
								outcome: 'retryable',
								result,
								error: `Unknown migration store: ${result.store}`,
							}
						}

						const body =
							result.ok === true
								? result.payload
								: { error: result.error }

						try {
							await axios.post(url, body)
							return {
								outcome:
									result.ok === true ? 'committed' : 'permanent',
								result,
								error: result.error,
							}
						} catch (e) {
							// The commit itself never landed (network, guard
							// rejection), so nothing was written and the row is
							// simply still outstanding. Retryable by definition.
							return {
								outcome: 'retryable',
								result,
								error:
									e?.response?.data?.message
									|| String(e?.message || e),
							}
						}
					}),
				)

				for (const entry of settled) {
					if (entry.outcome === 'committed') {
						committed += 1
						continue
					}

					if (entry.outcome === 'halt') {
						halt =
							halt
							?? (entry.error || 'Re-encryption could not be verified')
						continue
					}

					if (entry.outcome === 'retryable') {
						retryable.push({
							store: entry.result.store,
							id: entry.result.id,
							name: namesById.get(entry.result.id) ?? null,
							error: entry.error || 'Temporarily failed',
						})
						continue
					}

					failures.push({
						store: entry.result.store,
						id: entry.result.id,
						name: namesById.get(entry.result.id) ?? null,
						error: entry.error || 'Migration failed',
						unrecoverable: true,
					})
				}

				if (halt !== null) {
					break
				}
			}

			return { committed, failures, retryable, halt }
		},

		/**
		 * The per-record endpoint for one store.
		 *
		 * Store-specific paths, not a generic `{store, id}` — see the change's
		 * design note on why a store-name parameter on a per-object write path
		 * is an IDOR footgun.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {string} store One of MIGRATION_STORES.
		 * @param {string} id The record ID.
		 * @return {string|null} The URL, or null for an unknown store.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		recordUrl(migrationId, store, id) {
			const base = `/apps/keepiq/api/v1/migrations/${migrationId}`

			if (store === MIGRATION_STORES.SECRETS) {
				return generateUrl(`${base}/secrets/${id}`)
			}
			if (store === MIGRATION_STORES.VERSIONS) {
				return generateUrl(`${base}/versions/${id}`)
			}
			if (store === MIGRATION_STORES.ATTACHMENT_GRANTS) {
				return generateUrl(`${base}/attachment-grants/${id}`)
			}

			return null
		},

		/**
		 * Ask the server to terminate the migration.
		 *
		 * The server refuses while any row still points at the old suite, so a
		 * rejection here means work remains — the resume banner picks it up on
		 * the next unlock rather than the migration being lost.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {boolean} hasErrors Whether any record failed.
		 * @return {Promise<void>}
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		/**
		 * Try to finalise a run, and surface — rather than throw — the case where
		 * the server wants a loss acknowledged.
		 *
		 * A run with no failures finalises straight away. A run with failures is
		 * refused by the server with `migration_incomplete` until the user has
		 * seen which secrets would lose access and agreed, so this records that
		 * pending decision in store state for the recovery dialog to render. The
		 * migration stays `in_progress` and resumable in the meantime, which is
		 * the safe place to be parked.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {object} outcome The run outcome from runMigration.
		 * @return {Promise<{finalised: boolean, needsAcknowledgement: boolean, message: string|null}>}
		 *   Whether the migration terminated, and why not if it did not.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		async finaliseMigration(migrationId, outcome) {
			this.migrationNeedsAcknowledgement = false
			this.migrationBlockedMessage = null

			try {
				await this.completeMigration(migrationId, outcome.failed > 0)
				return {
					finalised: true,
					needsAcknowledgement: false,
					message: null,
				}
			} catch (e) {
				const status = e?.response?.status
				const code = e?.response?.data?.error
				const message = e?.response?.data?.message || String(e?.message || e)

				if (status === 409 && code === 'migration_incomplete') {
					// Expected, not exceptional: either work remains (resume) or a
					// loss needs acknowledging (the dialog asks). Either way the
					// migration is intact.
					//
					// `requiredAcknowledgement` is present only in the
					// acknowledgement case, and it is the ONLY number that will
					// satisfy the server. Absent means rows nobody has attempted
					// yet, which resuming fixes rather than acknowledging.
					const required =
						e?.response?.data?.requiredAcknowledgement ?? null
					this.migrationRequiredAcknowledgement = required

					const needsAcknowledgement = required !== null
					this.migrationNeedsAcknowledgement = needsAcknowledgement
					this.migrationBlockedMessage = message
					return {
						finalised: false,
						needsAcknowledgement,
						requiredAcknowledgement: required,
						message,
					}
				}

				throw e
			}
		},

		/**
		 * Finalise a run whose losses the user has explicitly accepted.
		 *
		 * Locks the old suite, so the accepted secrets lose access. Only ever
		 * called from an affirmative user action.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {number} acceptUnrecoverable How many losses the user accepted.
		 * @return {Promise<object>} The completion response.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
		 */
		async acceptMigrationLosses(migrationId, acceptUnrecoverable = null) {
			// Defaults to the server's own number. A caller may still pass one
			// explicitly, but the stored value is what the server asked for and
			// is therefore what it will accept.
			const accepted =
				acceptUnrecoverable ?? this.migrationRequiredAcknowledgement

			if (accepted === null) {
				throw new Error(
					'Cannot accept losses before the server has stated how many there are.',
				)
			}

			const data = await this.completeMigration(migrationId, true, accepted)
			this.migrationNeedsAcknowledgement = false
			this.migrationRequiredAcknowledgement = null
			this.migrationBlockedMessage = null
			return data
		},

		/**
		 * Ask the server to terminate the migration.
		 *
		 * Thin wrapper over the endpoint: the decision-making lives in
		 * finaliseMigration and acceptMigrationLosses, and the server owns the
		 * gate. Always refreshes migration status afterwards, including on
		 * failure, so a refused completion leaves the UI showing the truth.
		 *
		 * @param {string} migrationId The migration ID.
		 * @param {boolean} hasErrors Whether any record failed.
		 * @param {number|null} acceptUnrecoverable Losses the user has accepted.
		 * @return {Promise<object>} The completion response body.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
		 */
		async completeMigration(migrationId, hasErrors, acceptUnrecoverable = null) {
			try {
				const body = { hasErrors }
				// The server refuses to finalise a migration that would cost the
				// user access to a secret unless the count is acknowledged. Passing
				// it is therefore a deliberate act, never a default: callers only
				// supply it once the user has seen the list and agreed.
				if (acceptUnrecoverable !== null) {
					body.acceptUnrecoverable = acceptUnrecoverable
				}

				const { data } = await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/migrations/${migrationId}/complete`,
					),
					body,
				)
				this.migrationDroppedVersions =
					data.droppedVersions ?? this.migrationDroppedVersions
				this.migrationUnrecoverable = data.unrecoverable ?? []
				return data
			} finally {
				await this.fetchMigrationStatus()
			}
		},

		/**
		 * How many records an interrupted migration still has to move.
		 *
		 * The resume banner needs a number, and the only trustworthy one is
		 * derived server-side from the rows themselves — a client-side count
		 * would be lost with the tab that produced it. Asks for the smallest
		 * possible page because only the totals are wanted.
		 *
		 * @return {Promise<number|null>} Records remaining, or null when there is
		 *   no migration to resume.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
		 */
		async fetchMigrationRemaining() {
			if (this.migrationStatus === null) {
				this.migrationRemaining = null
				return null
			}

			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/keepiq/api/v1/migrations/${this.migrationStatus.id}/work`,
					),
					{ params: { limit: 1 } },
				)
				this.migrationRemaining = data.totalRemaining ?? 0
			} catch (e) {
				// A banner that cannot count is still worth showing, so leave the
				// count null rather than hiding the migration entirely.
				this.migrationRemaining = null
			}

			return this.migrationRemaining
		},

		/**
		 * Resume an interrupted migration, given the old master password.
		 *
		 * Driven by the resume banner. The vault is already unlocked under the
		 * NEW password, so the session holds the new private key and certificate
		 * and the only thing missing is the old private key — which is why this
		 * asks for the old master password and nothing else.
		 *
		 * @param {string} oldPassword The old master password.
		 * @return {Promise<{migrated: number, failed: number, droppedVersions: number,
		 *   failures: Array<object>, usedWorker: boolean}>} The migration outcome.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		async resumeMigration(oldPassword) {
			await this.fetchMigrationStatus()
			if (this.migrationStatus === null) {
				throw new Error('There is no migration to resume')
			}

			const session = useSessionStore()
			if (session.cryptoKey === null) {
				throw new Error('Unlock the vault before resuming the migration')
			}

			const migrationId = this.migrationStatus.id

			// Both suites are resolved from the MIGRATION, never from the session.
			// During a migration two suites are active, and the session binds to
			// whichever one the suite list yielded first — historically the OLD
			// one. Taking the new key from `session.cryptoKey` therefore risked
			// handing the pipeline the old private key as its verification key, so
			// every single record would fail its round-trip and the run would look
			// like a vault full of corrupt data instead of one mis-wired resume.
			const { data: oldSuite } = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/suites/${this.migrationStatus.oldSuiteId}`,
				),
			)
			const { data: newSuite } = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/suites/${this.migrationStatus.newSuiteId}`,
				),
			)

			// The session must be bound to the migration's NEW suite, because
			// session.cryptoKey is the verification key the pipeline will use.
			// Bound to the old suite it would verify new ciphertext against the
			// OLD private key: every record would fail its round-trip and the run
			// would look like a vault full of corrupt data rather than one
			// mis-wired resume. Suite resolution now deterministically prefers the
			// newest active suite, so this is a transient state at worst — say so
			// instead of guessing at a key.
			if (session.suiteId !== newSuite.id) {
				throw new Error(
					'Your session is unlocked against the previous encryption suite. '
						+ 'Lock and unlock the vault, then resume the migration.',
				)
			}

			this.migrationFailures = []
			this.migrationDroppedVersions = 0

			const outcome = await this.runMigration({
				migrationId,
				oldEncryptedPrivateKey: oldSuite.privateKey,
				oldPassword,
				// Taken from the migration's own new suite, not from the session.
				newPublicKeyPem: newSuite.certificate ?? newSuite.publicKey,
				newPrivateKey: session.cryptoKey,
			})

			await this.finaliseMigration(migrationId, outcome)

			return outcome
		},

		/**
		 * Discard migration progress state and terminate any running worker.
		 *
		 * @return {void}
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		resetMigrationState() {
			this.migrationProgress = null
			this.migrationFailures = []
			this.migrationDroppedVersions = 0
			this.migrationUnrecoverable = []
			this.migrationNeedsAcknowledgement = false
			this.migrationRequiredAcknowledgement = null
			this.migrationBlockedMessage = null
			if (this.migrationRunner) {
				this.migrationRunner.dispose()
				this.migrationRunner = null
			}
		},

		/**
		 * Register this store's migration teardown with the vault-lock lifecycle,
		 * so a locked vault leaves no worker holding key clones.
		 *
		 * @return {void}
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		registerLockReset() {
			onVaultLock(() => this.resetMigrationState())
		},

		/**
		 * Revoke the current user's active encryption suite.
		 *
		 * @param {string} reason The reason for revocation
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async revokeSuite(reason) {
			if (!this.currentSuite) {
				throw new Error('No active suite to revoke')
			}

			const response = await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/suites/${this.currentSuite.id}/revoke`,
				),
				{ reason },
			)

			this.currentSuite = response.data

			// A revoked suite must not leave a readable offline copy behind.
			try {
				const { useOfflineStore } = await import('./offline.js')
				await useOfflineStore().evict()
			} catch (e) {
				// Offline cache absent — nothing to evict.
			}
		},

		/**
		 * Check migration status.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async fetchMigrationStatus() {
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/migrations/status'),
				)
				this.migrationStatus =
					response.data.status === 'none' ? null : response.data
			} catch {
				this.migrationStatus = null
			}
		},
	},
})
