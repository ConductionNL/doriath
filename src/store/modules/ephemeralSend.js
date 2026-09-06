/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for ephemeral sends (ephemeral-send §5).
 *
 * All crypto runs in the browser (ADR-003): the payload is encrypted
 * AES-256-GCM with a fresh content key; with no password the raw key
 * rides the URL fragment (never sent anywhere), with a password the key
 * is wrapped with an Argon2id-derived KEK and only the wrapped key +
 * salt reach the server.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { deriveAesKeyArgon2id } from '../../crypto/argon2.js'

const IV_LENGTH = 12

/**
 * Base64-encode bytes.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string}
 */
function toBase64(bytes) {
	let binary = ''
	for (const b of bytes) {
		binary += String.fromCharCode(b)
	}
	return btoa(binary)
}

/**
 * Base64-decode to bytes.
 *
 * @param {string} base64 The base64 string.
 * @return {Uint8Array}
 */
function fromBase64(base64) {
	const binary = atob(base64)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}
	return bytes
}

/**
 * URL-fragment-safe base64url encode/decode for the content key.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string}
 */
function toBase64Url(bytes) {
	return toBase64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * Decode a base64url fragment key.
 *
 * @param {string} base64url The base64url string.
 * @return {Uint8Array}
 */
function fromBase64Url(base64url) {
	const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/')
	return fromBase64(base64 + '='.repeat((4 - (base64.length % 4)) % 4))
}

/**
 * AES-256-GCM encrypt with an IV-prefixed base64 result.
 *
 * @param {CryptoKey} key The AES key.
 * @param {Uint8Array} plaintext The plaintext bytes.
 * @return {Promise<string>} base64(IV||ciphertext).
 */
async function aesEncrypt(key, plaintext) {
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext),
	)
	const combined = new Uint8Array(iv.length + ciphertext.length)
	combined.set(iv, 0)
	combined.set(ciphertext, iv.length)
	return toBase64(combined)
}

/**
 * AES-256-GCM decrypt an IV-prefixed base64 blob.
 *
 * @param {CryptoKey} key The AES key.
 * @param {string} blob base64(IV||ciphertext).
 * @return {Promise<Uint8Array>} The plaintext bytes.
 */
async function aesDecrypt(key, blob) {
	const combined = fromBase64(blob)
	const iv = combined.slice(0, IV_LENGTH)
	const ciphertext = combined.slice(IV_LENGTH)
	return new Uint8Array(
		await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext),
	)
}

export const useEphemeralSendStore = defineStore('ephemeralSend', {
	state: () => ({
		/** @type {Array<object>} The caller's sends (metadata only). */
		sends: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
	}),

	actions: {
		/**
		 * Create a send: encrypt in the browser, POST ciphertext, and
		 * return the one-time link.
		 *
		 * @param {object} params The parameters.
		 * @param {string} params.payload The plaintext payload.
		 * @param {string} params.payloadType 'text' | 'credential'.
		 * @param {number} params.maxViews Max views (>=1).
		 * @param {number} params.ttlSeconds Optional TTL (0 = none).
		 * @param {string} params.password Optional password ('' = fragment mode).
		 * @return {Promise<string>} The full share URL (fragment included when keyless).
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
		 */
		async createSend({ payload, payloadType, maxViews, ttlSeconds, password }) {
			const contentKey = await crypto.subtle.generateKey(
				{ name: 'AES-GCM', length: 256 },
				true,
				['encrypt', 'decrypt'],
			)
			const rawKey = new Uint8Array(
				await crypto.subtle.exportKey('raw', contentKey),
			)
			const encryptedPayload = await aesEncrypt(
				contentKey,
				new TextEncoder().encode(payload),
			)

			const body = {
				encryptedPayload,
				payloadType,
				maxViews,
				ttlSeconds: ttlSeconds || 0,
				hasPassword: password !== '',
			}
			if (password !== '') {
				const salt = crypto.getRandomValues(new Uint8Array(16))
				const kek = await deriveAesKeyArgon2id(password, salt)
				body.wrappedKey = await aesEncrypt(kek, rawKey)
				body.argon2idSalt = toBase64(salt)
			}

			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/sends'),
				body,
			)
			const token = response.data?.token
			await this.fetchSends()

			// The /public shell serves the SPA as #[PublicPage] so an
			// account-less recipient reaches the access route. A PATH, not
			// a hash route: the router is createWebHistory and never reads
			// the fragment (the fragment is reserved for the key below).
			const base =
				window.location.origin
				+ generateUrl('/apps/keepiq/public')
				+ '/send/'
				+ encodeURIComponent(token)
			// Fragment-mode: the content key NEVER reaches the server — it
			// rides in the URL fragment (`#k=`), which the browser does not
			// transmit. A real `?k=` query would be sent (and logged) on
			// every load of the link.
			return password !== '' ? base : `${base}#k=${toBase64Url(rawKey)}`
		},

		/**
		 * Load the caller's sends.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-manage-and-revoke-sends
		 */
		async fetchSends() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/sends'),
				)
				this.sends = response.data || []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Revoke one of the caller's sends.
		 *
		 * @param {string} id The send id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-manage-and-revoke-sends
		 */
		async revoke(id) {
			await axios.delete(generateUrl(`/apps/keepiq/api/v1/sends/${id}`))
			this.sends = this.sends.filter((s) => s.id !== id)
		},

		/**
		 * Anonymous access: fetch + decrypt, then confirm the view.
		 *
		 * @param {string} token The URL token.
		 * @param {string} fragmentKey The base64url fragment key ('' in password mode).
		 * @param {string} password The password ('' in fragment mode).
		 * @return {Promise<{payload: string, payloadType: string, burned: boolean}>}
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-burn-after-read-and-optional-expiry
		 */
		async accessSend(token, fragmentKey, password) {
			const response = await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/public/sends/${encodeURIComponent(token)}/access`,
				),
			)
			const data = response.data

			let contentKey
			try {
				let rawKey
				if (data.hasPassword) {
					const kek = await deriveAesKeyArgon2id(
						password,
						fromBase64(data.argon2idSalt),
					)
					rawKey = await aesDecrypt(kek, data.wrappedKey)
				} else {
					rawKey = fromBase64Url(fragmentKey)
				}
				contentKey = await crypto.subtle.importKey(
					'raw',
					rawKey,
					{ name: 'AES-GCM' },
					false,
					['decrypt'],
				)
				const plaintext = await aesDecrypt(contentKey, data.encryptedPayload)
				const confirm = await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/public/sends/${encodeURIComponent(token)}/confirm`,
					),
				)
				return {
					payload: new TextDecoder().decode(plaintext),
					payloadType: data.payloadType,
					burned: confirm.data?.burned === true,
				}
			} catch (e) {
				if (data.hasPassword) {
					// Report the failed password attempt (burns at 5); the
					// caller surfaces attemptsLeft.
					const failure = await axios.post(
						generateUrl(
							`/apps/keepiq/api/v1/public/sends/${encodeURIComponent(token)}/failure`,
						),
					)
					const err = new Error('wrong-password')
					err.attemptsLeft = failure.data?.attemptsLeft ?? 0
					err.burned = failure.data?.burned === true
					throw err
				}
				throw e
			}
		},
	},
})
