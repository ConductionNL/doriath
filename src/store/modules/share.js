/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for user-to-user share targets.
 *
 * The store wraps `/api/v1/secrets/{secretId}/shares` (list + create) and
 * `/api/v1/shares/{id}` (revoke). It exposes a small helper for browser-side
 * RSA encryption so callers can pass a plaintext snapshot and a recipient
 * certificate without re-implementing the envelope; the helper imports the
 * recipient's public key and encrypts each field via `rsaEncrypt`.
 *
 * The plaintext NEVER leaves the browser — the helper returns the encrypted
 * blobs as a `{ fieldName: base64 }` map ready to POST to the recipient's
 * Secret copy endpoint and to record as a share target.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-11.1
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { importPublicKey, rsaEncrypt } from '../../crypto/index.js'

/**
 * How many candidates one shareability probe may name.
 *
 * The server's own bound (ShareController::MAX_RECIPIENT_PROBE) — matched here
 * so an over-long sharee page is trimmed before it becomes a 400 that reads
 * like "nobody is shareable".
 *
 * @type {number}
 */
const MAX_RECIPIENT_PROBE = 100

export const useShareStore = defineStore('share', {
	state: () => ({
		/** @type {Array<object>} The shares for the currently focused secret. */
		shares: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
		/**
		 * Prospective recipients from the last candidate search, in the order
		 * Nextcloud's sharee search returned them.
		 *
		 * @type {Array<{userId: string, shareable: boolean, reason: string|null}>}
		 */
		recipientCandidates: [],
		/** @type {boolean} Whether a candidate search is in flight. */
		candidatesLoading: false,
	}),

	getters: {
		/**
		 * Number of distinct recipients currently sharing the secret.
		 *
		 * @param {object} state The store state.
		 * @return {number}
		 */
		recipientCount: (state) => state.shares.length,
	},

	actions: {
		/**
		 * The users a secret could be shared with, and which of them can
		 * actually receive one.
		 *
		 * TWO STEPS, because the server deliberately offers no third option.
		 * Nextcloud's sharee search says WHO the caller may share with at all
		 * (it is permission-filtered, and honours the instance's
		 * share-with-group-members-only and autocomplete limits); keepiq's
		 * batch probe then says which of those hold an active suite, i.e. a
		 * public key to encrypt a copy to.
		 *
		 * There is no endpoint that lists suite holders, and that is a
		 * decision rather than a gap: a certificate is a public key and safe
		 * to hand out, but "who has a keepiq vault" is a membership fact
		 * gated by no sharing permission, so it is only ever answered about
		 * users the caller already named
		 * (ShareController::recipientCertificates).
		 *
		 * Non-shareable candidates are KEPT, flagged rather than dropped: the
		 * caller can then say why a colleague cannot be picked instead of
		 * leaving them mysteriously absent. An unknown user and one without a
		 * suite come back identically, on purpose — splitting them would make
		 * the endpoint a user-existence oracle — so a `reason` never means
		 * "this account exists".
		 *
		 * @param {string} [search] Sharee search term; '' asks for the first page.
		 *
		 * @return {Promise<Array<{userId: string, shareable: boolean, reason: string|null}>>}
		 *
		 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
		 */
		async searchRecipientCandidates(search = '') {
			this.candidatesLoading = true
			try {
				const userIds = await this.searchSharees(search)
				if (userIds.length === 0) {
					this.recipientCandidates = []
					return this.recipientCandidates
				}

				const response = await axios.post(
					generateUrl('/apps/keepiq/api/v1/shares/recipient-certificates'),
					{ userIds },
				)

				const recipients = response.data?.recipients
				this.recipientCandidates = (
					Array.isArray(recipients) ? recipients : []
				).map((recipient) => ({
					userId: String(recipient?.userId ?? ''),
					shareable: recipient?.shareable === true,
					reason: recipient?.reason ?? null,
				}))

				return this.recipientCandidates
			} finally {
				this.candidatesLoading = false
			}
		},

		/**
		 * The user ids Nextcloud's own sharee search returns for a term.
		 *
		 * `itemType=file` with `shareType=0` is the users-only form of the
		 * autocomplete every NC share dialog uses, so the candidate set is
		 * exactly the one the instance already permits this user to share
		 * with — keepiq neither widens it nor keeps a user directory of its
		 * own. Exact matches come first, which is the order the search itself
		 * distinguishes them in.
		 *
		 * @param {string} search The search term.
		 *
		 * @return {Promise<Array<string>>} Distinct user ids, capped at the
		 *   probe's own bound.
		 *
		 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
		 */
		async searchSharees(search) {
			const response = await axios.get(
				generateOcsUrl('apps/files_sharing/api/v1/sharees'),
				{
					// OCS refuses the call without the header and answers XML
					// without the format; neither is added for us.
					headers: { 'OCS-APIRequest': 'true' },
					params: {
						format: 'json',
						search,
						itemType: 'file',
						// Users only. Groups come from the provisioning API,
						// which needs no shareability probe.
						shareType: 0,
						perPage: MAX_RECIPIENT_PROBE,
						// No global address book: a remote lookup answers with
						// users this server cannot hold a suite for.
						lookup: false,
					},
				},
			)

			const data = response.data?.ocs?.data ?? {}
			const rows = [
				...(Array.isArray(data.exact?.users) ? data.exact.users : []),
				...(Array.isArray(data.users) ? data.users : []),
			]

			const userIds = []
			const seen = new Set()
			for (const row of rows) {
				const userId = String(row?.value?.shareWith ?? '')
				if (userId === '' || seen.has(userId)) {
					continue
				}
				seen.add(userId)
				userIds.push(userId)
			}

			return userIds.slice(0, MAX_RECIPIENT_PROBE)
		},

		/**
		 * Hydrate the share list for a source secret.
		 *
		 * @param {string} secretId The source secret ID.
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-visibility
		 */
		async fetchShares(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/shares`),
				)
				this.shares = response.data || []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load shares'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Encrypt a plaintext snapshot for a recipient.
		 *
		 * Mirrors what the server-side EncryptService would do for an internal
		 * application: imports the recipient's PEM certificate, then encrypts
		 * each field with RSA-OAEP-SHA256. The plaintext never leaves the
		 * browser; the return value is the `{ fieldName: base64 }` envelope.
		 *
		 * @param {object}              snapshot          Plaintext field map.
		 * @param {string}              publicCertificate The recipient's PEM certificate.
		 * @return {Promise<Record<string,string>>}
		 */
		async encryptForRecipient(snapshot, publicCertificate) {
			if (publicCertificate == null || publicCertificate === '') {
				throw new Error('Recipient has no active encryption suite')
			}
			const publicKey = await importPublicKey(publicCertificate)
			const out = {}
			for (const [field, value] of Object.entries(snapshot)) {
				if (value == null || value === '') {
					continue
				}
				out[field] = await rsaEncrypt(String(value), publicKey)
			}
			return out
		},

		/**
		 * Record a share target.
		 *
		 * Expects the caller to have already PUT the recipient's encrypted
		 * Secret copy through `useSecretStore`; this call only registers the
		 * link.
		 *
		 * @param {string}      secretId          The source secret ID.
		 * @param {string}      targetUserId      The recipient Nextcloud UID.
		 * @param {string}      recipientSecretId The recipient's Secret copy ID.
		 * @param {string|null} groupShareId      Optional group-share linkage.
		 * @return {Promise<object>}
		 */
		async createShare(
			secretId,
			targetUserId,
			recipientSecretId,
			groupShareId = null,
		) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/shares`),
					{ targetUserId, recipientSecretId, groupShareId },
				)
				this.shares.push(response.data)
				return response.data
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Failed to share'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * The write context of a secret for the current user
		 * (folder-permission-grades §4).
		 *
		 * @param {string} secretId The secret (source or copy) id.
		 * @return {Promise<object>} { sourceSecretId, effectiveGrade, ownerCertificate, sourceUpdatedAt }.
		 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-effective-grade-is-the-highest-grade-along-the-ancestor-folder-chain
		 */
		async fetchWriteContext(secretId) {
			const response = await axios.get(
				generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/write-context`),
			)
			return response.data
		},

		/**
		 * Write-grade member fan-out (folder-permission-grades §4.2):
		 * when the edited row is a recipient copy and the caller holds a
		 * `write` grade, re-encrypt the plaintext for the SOURCE row
		 * (owner certificate) and every recipient, then PUT the sync.
		 * No-op for owners/read grades.
		 *
		 * @param {string} editedSecretId The edited (copy) secret id.
		 * @param {object} plaintext New plaintext field map.
		 * @return {Promise<{updated: number}>}
		 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
		 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
		 */
		async syncAsTeamWriter(editedSecretId, plaintext) {
			const context = await this.fetchWriteContext(editedSecretId)
			if (
				context.effectiveGrade !== 'write'
				|| context.sourceSecretId === editedSecretId
			) {
				return { updated: 0 }
			}

			// Recipient rows of the SOURCE (write grade grants the list).
			const response = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/secrets/${context.sourceSecretId}/shares`,
				),
			)
			const shares = response.data || []

			const updates = []
			// The owner's SOURCE row itself, re-encrypted under the
			// owner's certificate.
			if (context.ownerCertificate) {
				const ownerBlob = await this.encryptForRecipient(
					plaintext,
					context.ownerCertificate,
				)
				updates.push({
					secretId: context.sourceSecretId,
					key: ownerBlob.key ?? null,
					login: ownerBlob.login ?? null,
					additionalFields: ownerBlob.additionalFields ?? null,
				})
			}
			for (const share of shares) {
				const certificate = share.recipientCertificate || share.certificate
				if (
					certificate == null
					|| certificate === ''
					|| share.secretId === editedSecretId
				) {
					// The writer's own copy was already updated by the
					// regular edit; suite-less recipients are skipped.
					continue
				}

				const blob = await this.encryptForRecipient(plaintext, certificate)
				updates.push({
					secretId: share.secretId,
					key: blob.key ?? null,
					login: blob.login ?? null,
					additionalFields: blob.additionalFields ?? null,
				})
			}

			if (updates.length === 0) {
				return { updated: 0 }
			}

			const syncResponse = await axios.put(
				generateUrl(
					`/apps/keepiq/api/v1/secrets/${context.sourceSecretId}/sync`,
				),
				{
					expectedUpdatedAt: context.sourceUpdatedAt ?? '',
					updates,
				},
			)
			return syncResponse.data
		},

		/**
		 * Revoke a share target (cascade-deletes the recipient's Secret copy
		 * server-side).
		 *
		 * @param {string} shareId The share-target row ID.
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
		 */
		async revokeShare(shareId) {
			this.loading = true
			this.error = null
			try {
				await axios.delete(
					generateUrl(`/apps/keepiq/api/v1/shares/${shareId}`),
				)
				this.shares = this.shares.filter((s) => s.id !== shareId)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to revoke share'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a batch of recipient share targets (group-share expansion).
		 *
		 * The caller supplies the already-encrypted Secret copies (one per
		 * member); this method records them and returns the server response
		 * for each.
		 *
		 * @param {string} secretId          The source secret ID.
		 * @param {Array<{targetUserId: string, recipientSecretId: string}>} recipients Recipient copies.
		 * @param {string} groupShareId      The group-share linkage ID.
		 * @return {Promise<Array<object>>}
		 */
		async createBatchShares(secretId, recipients, groupShareId) {
			const created = []
			for (const r of recipients) {
				// Sequential POST keeps the UI feedback predictable on slow
				// networks; total recipient count for a group is bounded by
				// the Nextcloud group size which is small in practice.

				const row = await this.createShare(
					secretId,
					r.targetUserId,
					r.recipientSecretId,
					groupShareId,
				)
				created.push(row)
			}
			return created
		},

		/**
		 * Sync an updated plaintext to every recipient. Implements
		 * tasks §11.5 (browser-side encryption loop) + §11.6 (called by
		 * useSecretStore.updateSecret after a successful owner update if
		 * the secret has active shares).
		 *
		 * Flow:
		 *  1. Hydrate the current share list for the secret (if not yet
		 *     loaded).
		 *  2. For each recipient, fetch their active EncryptionSuite
		 *     certificate and RSA-encrypt the new plaintext for them.
		 *  3. PUT the batch to `/api/v1/secrets/{id}/sync` along with the
		 *     `expectedUpdatedAt` for optimistic-lock validation.
		 *
		 * The server side (ShareService::syncUpdate) does the atomic
		 * write inside a transaction, clears the `possiblyCompromisedAt`
		 * flag if previously set, and rejects stale locks with HTTP 409
		 * so the caller can re-encrypt.
		 *
		 * @param {string}              secretId          The source secret ID.
		 * @param {object}              plaintext         New plaintext field map
		 *   ({ key, login, additionalFields } — only fields that should be
		 *   re-encrypted for recipients).
		 * @param {string}              expectedUpdatedAt The owner's last-seen updatedAt (ISO).
		 * @return {Promise<{updated: number}>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
		 */
		async syncUpdate(secretId, plaintext, expectedUpdatedAt) {
			this.loading = true
			this.error = null
			try {
				if (this.shares.length === 0) {
					await this.fetchShares(secretId)
				}

				if (this.shares.length === 0) {
					// No recipients — nothing to sync. The UI uses this
					// as a fast-path skip in useSecretStore.updateSecret.
					return { updated: 0 }
				}

				// Build the per-recipient encrypted blob batch. The
				// recipient's PEM certificate is returned by the share
				// row (the server hydrates it from the active
				// EncryptionSuite of the recipient).
				const updates = []
				for (const share of this.shares) {
					const certificate =
						share.recipientCertificate || share.certificate
					if (certificate == null || certificate === '') {
						// Skip recipients whose suite was revoked while the
						// owner edited; the server-side cascade has already
						// cleaned them up but the UI list may be stale.
						continue
					}

					const blob = await this.encryptForRecipient(
						plaintext,
						certificate,
					)
					updates.push({
						secretId: share.secretId,
						encryptedKey: blob.key ?? null,
						encryptedLogin: blob.login ?? null,
						encryptedAdditionalFields: blob.additionalFields ?? null,
					})
				}

				if (updates.length === 0) {
					return { updated: 0 }
				}

				const response = await axios.put(
					generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/sync`),
					{
						expectedUpdatedAt,
						updates,
					},
				)
				return response.data ?? { updated: updates.length }
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to sync update'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset the store (used on secret detail unmount).
		 *
		 * @return {void}
		 */
		reset() {
			this.shares = []
			this.error = null
		},
	},
})
