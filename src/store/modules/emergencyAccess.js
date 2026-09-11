/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for break-glass emergency access (add-emergency-access).
 *
 * The grantor's browser builds the recovery envelope client-side (hybrid-encrypt
 * the grantor's private key to the grantee's public certificate) and sends ONLY
 * the envelope ciphertext — the raw private key is re-derived transiently from
 * the session-wrapped blob with the grantor's master password and discarded
 * immediately after the envelope is built. The server never receives a usable
 * key (ADR-003). The grantee later recovers the grantor's key in their OWN
 * browser with their OWN private key.
 *
 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import {
	buildRecoveryEnvelope,
	openRecoveryEnvelope,
} from '../../crypto/emergencyEnvelope.js'
import { decryptPrivateKey } from '../../crypto/index.js'
import { buildKeyProofHeaders, PROOF_PURPOSE } from '../../crypto/keyProof.js'
import { useSessionStore } from './session.js'

export const useEmergencyAccessStore = defineStore('emergencyAccess', {
	state: () => ({
		/** @type {Array<object>} Contacts the current user designated (as grantor). */
		contacts: [],
		/** @type {Array<object>} Relationships where the current user is the grantee. */
		incoming: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last surfaced error. */
		error: null,
	}),

	getters: {
		/**
		 * @param state
		 * @return {Array<object>} Incoming relationships pending grantor decline.
		 */
		pendingRequests: (state) =>
			state.contacts.filter((c) => c.state === 'requested'),
		/**
		 * @param state
		 * @return {Array<object>} Relationships that need re-establishing after a key change.
		 */
		invalidatedContacts: (state) =>
			state.contacts.filter((c) => c.state === 'invalidated'),
	},

	actions: {
		/**
		 * Fetch the current user's designated emergency contacts.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
		 */
		async fetchContacts() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/emergency-access/contacts'),
				)
				this.contacts = Array.isArray(response.data) ? response.data : []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the relationships where the current user is the grantee.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
		 */
		async fetchIncoming() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/emergency-access/incoming'),
				)
				this.incoming = Array.isArray(response.data) ? response.data : []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Designate an emergency contact. Builds the recovery envelope in the
		 * browser and sends only ciphertext; the raw private key is discarded.
		 *
		 * @param {object} params The designation parameters.
		 * @param {string} params.granteeUserId The grantee Nextcloud user ID.
		 * @param {number} params.waitPeriodDays The wait period (1|3|7|30).
		 * @param {string} params.masterPassword The grantor's master password (to
		 *   transiently unwrap the private key for re-encryption; never sent).
		 * @return {Promise<object>} The created/updated contact.
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
		 */
		async designate({ granteeUserId, waitPeriodDays, masterPassword }) {
			const session = useSessionStore()
			if (session.isLocked || !session.encryptedPrivateKey) {
				throw new Error(
					'Unlock your vault before designating an emergency contact',
				)
			}

			// 1. Fetch the grantee's public certificate (validates active suite).
			const certResponse = await axios.get(
				generateUrl(
					'/apps/keepiq/api/v1/emergency-access/grantee-certificate',
				),
				{ params: { granteeUserId } },
			)
			const granteeCertificate = certResponse.data.certificate

			// 2. Transiently re-derive the grantor's private-key PEM, build the
			//    envelope, and discard the raw key. Only ciphertext leaves here.
			let privateKeyPem = await decryptPrivateKey(
				session.encryptedPrivateKey,
				masterPassword,
			)
			let recoveryEnvelope
			try {
				recoveryEnvelope = await buildRecoveryEnvelope(
					privateKeyPem,
					granteeCertificate,
				)
			} finally {
				privateKeyPem = null
			}

			// 3. Persist only the grantee-encrypted envelope.
			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/emergency-access/contacts'),
				{
					granteeUserId,
					waitPeriodDays,
					accessLevel: 'view',
					recoveryEnvelope,
				},
			)
			await this.fetchContacts()
			return response.data
		},

		/**
		 * Revoke an emergency contact (grantor).
		 *
		 * Deleting a contact destroys its recovery envelope — the only break-glass
		 * path that survives a private-key overwrite — so it carries a vault-key
		 * proof: the caller must prove the master password. The password is used
		 * only to sign and is never sent.
		 *
		 * @param {string} id The relationship ID.
		 * @param {string} masterPassword The current master password, for the proof.
		 * @return {Promise<void>}
		 * @spec openspec/changes/harden-vault-key-material-guards/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
		 */
		async revoke(id, masterPassword) {
			const session = useSessionStore()
			const headers = await buildKeyProofHeaders({
				suiteId: session.suiteId,
				purpose: PROOF_PURPOSE.EMERGENCY_DESTROY,
				encryptedPrivateKey: session.encryptedPrivateKey,
				masterPassword,
				boundValues: [id],
			})

			await axios.delete(
				generateUrl(`/apps/keepiq/api/v1/emergency-access/contacts/${id}`),
				{ headers },
			)
			await this.fetchContacts()
		},

		/**
		 * Initiate a break-glass request (grantee).
		 *
		 * @param {string} id The relationship ID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
		 */
		async request(id) {
			await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/emergency-access/contacts/${id}/request`,
				),
			)
			await this.fetchIncoming()
		},

		/**
		 * Decline a pending break-glass request (grantor veto).
		 *
		 * @param {string} id The relationship ID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-grantor-decline-veto
		 */
		async decline(id) {
			await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/emergency-access/contacts/${id}/decline`,
				),
			)
			await this.fetchContacts()
		},

		/**
		 * Recover the grantor's private key as the grantee after approval. Fetches
		 * the envelope and opens it with the grantee's OWN in-session private key.
		 *
		 * @param {string} id The relationship ID.
		 * @return {Promise<string>} The recovered grantor private-key PEM (in-memory).
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
		 */
		async recover(id) {
			const session = useSessionStore()
			if (session.isLocked) {
				throw new Error('Unlock your vault to recover emergency access')
			}
			const response = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/emergency-access/contacts/${id}/envelope`,
				),
			)
			const envelope = response.data.recoveryEnvelope
			return openRecoveryEnvelope(envelope, session.cryptoKey)
		},
	},
})
