/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for encrypted attachments (encrypted-attachments §6).
 *
 * All file crypto happens here, in the browser (ADR-003): upload
 * generates a random AES-256-GCM file key, encrypts the bytes and the
 * `{filename, contentType}` metadata under it, RSA-wraps the key under
 * the owner's public certificate, and POSTs ciphertext only. Download
 * unwraps the file key with the in-session CryptoKey, decrypts, and
 * triggers a local save — plaintext never touches storage or the wire.
 * Sharing re-wraps only the tiny file key per recipient; the blob is
 * never re-uploaded.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { importPublicKey, rsaDecrypt, rsaEncrypt } from '../../crypto/index.js'
import { useSessionStore } from './session.js'

/** AES-GCM IV length in bytes. */
const IV_LENGTH = 12

/**
 * Encode bytes to base64.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string} Base64.
 */
function toBase64(bytes) {
	let binary = ''
	for (let i = 0; i < bytes.length; i += 0x8000) {
		binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
	}
	return btoa(binary)
}

/**
 * Decode base64 to bytes.
 *
 * @param {string} base64 The base64 string.
 * @return {Uint8Array} The bytes.
 */
function fromBase64(base64) {
	return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0))
}

/**
 * AES-256-GCM encrypt bytes under a key, IV-prefixed.
 *
 * @param {CryptoKey} key The AES key.
 * @param {Uint8Array} bytes The plaintext bytes.
 * @return {Promise<Uint8Array>} IV + ciphertext.
 */
async function aesEncryptBytes(key, bytes) {
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, bytes),
	)
	const out = new Uint8Array(IV_LENGTH + ciphertext.length)
	out.set(iv, 0)
	out.set(ciphertext, IV_LENGTH)
	return out
}

/**
 * AES-256-GCM decrypt IV-prefixed bytes.
 *
 * @param {CryptoKey} key The AES key.
 * @param {Uint8Array} data IV + ciphertext.
 * @return {Promise<Uint8Array>} The plaintext bytes.
 */
async function aesDecryptBytes(key, data) {
	const iv = data.subarray(0, IV_LENGTH)
	const ciphertext = data.subarray(IV_LENGTH)
	return new Uint8Array(
		await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext),
	)
}

export const useAttachmentStore = defineStore('attachment', {
	state: () => ({
		/** @type {Array<object>} Attachments of the focused secret (decrypted metadata). */
		attachments: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
		/**
		 * @type {number} Monotonic id of the newest list request. A response
		 * whose id has been superseded does not write to the store — the
		 * sidebar swaps `:id` without remounting, so a slow response for the
		 * previous secret can otherwise land after the current one.
		 */
		listRequestId: 0,
	}),

	actions: {
		/**
		 * Unwrap an attachment's file key with the session CryptoKey.
		 *
		 * @param {string} wrappedFileKey The RSA-wrapped base64 raw key.
		 * @return {Promise<CryptoKey>} The AES-GCM file key.
		 */
		async unwrapFileKey(wrappedFileKey) {
			const session = useSessionStore()
			if (!session.cryptoKey) {
				throw new Error('Vault is locked')
			}
			const rawBase64 = await rsaDecrypt(wrappedFileKey, session.cryptoKey)
			return crypto.subtle.importKey(
				'raw',
				fromBase64(rawBase64),
				{ name: 'AES-GCM' },
				false,
				['encrypt', 'decrypt'],
			)
		},

		/**
		 * List a secret's attachments and decrypt their metadata.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
		 */
		async fetchAttachments(secretId) {
			const requestId = ++this.listRequestId
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/keepiq/api/v1/secrets/${secretId}/attachments`,
					),
				)
				const rows = []
				for (const row of response.data || []) {
					let meta = { filename: null, contentType: null }
					try {
						const key = await this.unwrapFileKey(row.wrappedFileKey)

						const metaBytes = await aesDecryptBytes(
							key,
							fromBase64(row.encryptedMetadata),
						)
						meta = JSON.parse(new TextDecoder().decode(metaBytes))
					} catch {
						// Honest failure: undecryptable metadata shows as invalid.
					}
					rows.push({
						id: row.id,
						sizeBytes: row.sizeBytes,
						createdAt: row.createdAt,
						wrappedFileKey: row.wrappedFileKey,
						filename: meta.filename ?? null,
						contentType: meta.contentType ?? null,
					})
				}
				if (requestId === this.listRequestId) {
					this.attachments = rows
				}
			} catch (e) {
				const message =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load attachments'
				if (requestId === this.listRequestId) {
					this.error = message
				}
				throw e
			} finally {
				if (requestId === this.listRequestId) {
					this.loading = false
				}
			}
		},

		/**
		 * Encrypt and upload a file against an owned secret.
		 *
		 * @param {string} secretId The owning secret id.
		 * @param {File} file The picked file.
		 * @return {Promise<void>}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
		 */
		async upload(secretId, file) {
			const session = useSessionStore()
			if (!session.certificate) {
				throw new Error('Vault is locked')
			}
			this.loading = true
			this.error = null
			try {
				const fileKey = await crypto.subtle.generateKey(
					{ name: 'AES-GCM', length: 256 },
					true,
					['encrypt', 'decrypt'],
				)
				const bytes = new Uint8Array(await file.arrayBuffer())
				const blob = await aesEncryptBytes(fileKey, bytes)
				const metaBytes = new TextEncoder().encode(
					JSON.stringify({
						filename: file.name,
						contentType: file.type || 'application/octet-stream',
					}),
				)
				const encryptedMetadata = toBase64(
					await aesEncryptBytes(fileKey, metaBytes),
				)

				const rawKey = new Uint8Array(
					await crypto.subtle.exportKey('raw', fileKey),
				)
				const publicKey = await importPublicKey(session.certificate)
				const wrappedFileKey = await rsaEncrypt(toBase64(rawKey), publicKey)

				await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/secrets/${secretId}/attachments`,
					),
					{
						blob: toBase64(blob),
						encryptedMetadata,
						wrappedFileKey,
					},
				)
				await this.fetchAttachments(secretId)
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Upload failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Download and decrypt an attachment, then trigger a local save.
		 *
		 * @param {object} attachment The listed attachment row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
		 */
		async download(attachment) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/keepiq/api/v1/attachments/${attachment.id}/blob`,
					),
				)
				const key = await this.unwrapFileKey(attachment.wrappedFileKey)
				const plaintext = await aesDecryptBytes(
					key,
					fromBase64(response.data.blob),
				)
				const blob = new Blob([plaintext], {
					type: attachment.contentType || 'application/octet-stream',
				})
				const url = URL.createObjectURL(blob)
				const a = document.createElement('a')
				a.href = url
				a.download = attachment.filename || 'attachment'
				a.click()
				URL.revokeObjectURL(url)
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Download failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete an attachment (owner-only).
		 *
		 * @param {string} secretId The owning secret id (for the refresh).
		 * @param {string} attachmentId The attachment id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-operations-are-auditable
		 */
		async remove(secretId, attachmentId) {
			this.loading = true
			this.error = null
			try {
				await axios.delete(
					generateUrl(`/apps/keepiq/api/v1/attachments/${attachmentId}`),
				)
				await this.fetchAttachments(secretId)
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Delete failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Re-wrap the file keys of a secret's attachments for a recipient
		 * copy (share flow): unwrap each with the owner's session key,
		 * re-wrap under the recipient certificate, POST the grants. The
		 * blob is never touched (encrypted-attachments §6.3).
		 *
		 * @param {string} sourceSecretId The owner's secret id.
		 * @param {string} copySecretId The recipient's copy id.
		 * @param {string} recipientId The recipient user id.
		 * @param {string} recipientCertificate The recipient's PEM certificate.
		 * @return {Promise<number>} Grants created.
		 */
		async regrantForRecipient(
			sourceSecretId,
			copySecretId,
			recipientId,
			recipientCertificate,
		) {
			const session = useSessionStore()
			if (!session.cryptoKey) {
				throw new Error('Vault is locked')
			}
			const response = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/secrets/${sourceSecretId}/attachments`,
				),
			)
			const recipientKey = await importPublicKey(recipientCertificate)
			let created = 0
			for (const row of response.data || []) {
				const rawBase64 = await rsaDecrypt(
					row.wrappedFileKey,
					session.cryptoKey,
				)

				const rewrapped = await rsaEncrypt(rawBase64, recipientKey)

				await axios.post(
					generateUrl(`/apps/keepiq/api/v1/attachments/${row.id}/grants`),
					{
						copySecretId,
						recipientId,
						wrappedFileKey: rewrapped,
						recipientType: 'user',
					},
				)
				created++
			}
			return created
		},

		/**
		 * Reset the store (secret detail unmount, or a secret switch).
		 *
		 * Bumping the request id retires any list request still in flight,
		 * so its response cannot repopulate the store after the reset. The
		 * spinner is cleared for the same reason: without it, a reset while
		 * a fetch was running left `loading` true with nothing to finish it.
		 *
		 * @return {void}
		 * @spec exclude Store lifecycle; states no attachment behaviour of
		 * its own.
		 */
		reset() {
			this.listRequestId++
			this.attachments = []
			this.error = null
			this.loading = false
		},
	},
})
