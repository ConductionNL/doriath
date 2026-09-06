import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { importPublicKey, rsaDecrypt, rsaEncrypt } from '../../crypto/index.js'
import { PASSKEY_TYPE_NAME, passkeyRpId } from '../../passkey/passkey.js'
import { useOfflineStore } from './offline.js'
import { useSecretTypeStore } from './secretType.js'
import { useSessionStore } from './session.js'

/**
 * Pinia store for secrets.
 *
 * The store owns the end-to-end model: it sends RSA ciphertext to the API
 * (encrypted in the browser with the owner's public certificate) and
 * decrypts the blobs it receives using the session CryptoKey. The server
 * never sees plaintext (ADR-003).
 */

/**
 * The query that means "every secret in this vault".
 *
 * The bulk paths (export, transfer, import reconciliation) pass this
 * EXPLICITLY so they never inherit the list's stored folder / type / search
 * filters. Without it, making a bare `fetchSecrets()` reuse the stored query
 * would quietly shrink an export to whichever folder the user happened to be
 * browsing — the mirror image of the bug that motivated storing it at all.
 *
 * @type {{folderId: null, typeId: null, search: string}}
 */
const WHOLE_VAULT = { folderId: null, typeId: null, search: '' }
export const useSecretStore = defineStore('secret', {
	state: () => ({
		/** @type {Array<object>} The current page of secrets (metadata + ciphertext). */
		secrets: [],
		/** @type {object|null} The currently opened secret with decrypted fields. */
		currentSecret: null,
		/** @type {number} Total number of matching secrets (for pagination). */
		totalCount: 0,
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {object} Active list filters. */
		filters: { folderId: null, search: '', typeId: null },
		/** @type {object} Active sort. */
		sort: { field: 'name', direction: 'asc' },
		/** @type {number} The current 1-based page. */
		page: 1,
		/** @type {number} The page size. */
		limit: 50,
	}),

	actions: {
		/**
		 * Record what the vault list is currently showing, so a refresh that
		 * knows nothing about the view can reproduce it.
		 *
		 * The list used to pass `folderId` / `typeId` / `search` on every call
		 * and nothing ever wrote them to `filters`, so they were permanently
		 * null. Any bare `fetchSecrets()` — the sidebar's post-edit/move/delete
		 * refresh, FolderMoveDialog's — therefore silently dropped the folder
		 * filter and replaced a folder's contents with the WHOLE vault: move a
		 * secret out of a folder and every other secret appeared in it. The
		 * sidebar's own comment claimed a bare call "reuses the store's active
		 * filters"; this is what makes that true.
		 *
		 * Sort lives here too, so a refresh does not silently reorder the list.
		 *
		 * @param {object} query The list's active query.
		 * @param {string|null} [query.folderId] Folder being browsed (null = vault root).
		 * @param {string} [query.search] Active search term.
		 * @param {string|null} [query.typeId] Active secret-type filter.
		 * @param {string} [query.sort] Active sort field.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		setListQuery(query = {}) {
			if ('folderId' in query) this.filters.folderId = query.folderId ?? null
			if ('search' in query) this.filters.search = query.search ?? ''
			if ('typeId' in query) this.filters.typeId = query.typeId ?? null
			if ('sort' in query && query.sort) this.sort.field = query.sort
		},

		/**
		 * Fetch a page of secrets (list or search).
		 *
		 * Anything not passed falls back to the stored list query, so the
		 * sidebar and the folder dialogs can refresh with `fetchSecrets()` and
		 * get the list the user is actually looking at. See setListQuery.
		 *
		 * @param {object} options Optional overrides for filters/sort/page.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 * @spec openspec/specs/secrets/spec.md#requirement-search
		 */
		async fetchSecrets(options = {}) {
			this.loading = true
			try {
				// Offline (served from cache): list from the decrypted snapshot
				// instead of the live API (offline-readonly-cache §4.2).
				const offline = useOfflineStore()
				if (offline.servedFromCache && offline.vault) {
					// PRESENCE, not nullishness: an explicit null means "no filter"
					// (vault root, or a bulk fetch of the whole vault), which `??`
					// would silently turn back into the stored filter.
					const folderId =
						'folderId' in options
							? options.folderId
							: this.filters.folderId
					const search = (
						('search' in options ? options.search : this.filters.search)
						?? ''
					).toLowerCase()
					// Same presence semantics as folderId, mirroring the online
					// branch's params.typeId — without it a type-filtered list
					// silently showed the whole vault while offline.
					const typeId =
						'typeId' in options ? options.typeId : this.filters.typeId
					let items = offline.vault.secrets
					if (folderId) {
						items = items.filter((s) => s.folderId === folderId)
					}
					if (typeId) {
						items = items.filter((s) => s.typeId === typeId)
					}
					if (search) {
						items = items.filter(
							(s) =>
								(s.name || '').toLowerCase().includes(search)
								|| (s.url || '').toLowerCase().includes(search),
						)
					}
					this.secrets = items
					this.totalCount = items.length
					this.page = 1
					return
				}

				const params = {
					page: options.page ?? this.page,
					limit: options.limit ?? this.limit,
					sort: options.sort ?? this.sort.field,
					direction: options.direction ?? this.sort.direction,
				}
				// PRESENCE, not nullishness — see the offline branch above.
				const folderId =
					'folderId' in options ? options.folderId : this.filters.folderId
				if (folderId) {
					params.folderId = folderId
				}
				const search =
					'search' in options ? options.search : this.filters.search
				if (search) {
					params.search = search
				}
				// Server-side secret-type filter (passkey-item-type §3.3):
				// lets the vault list show only one type, e.g. passkeys.
				const typeId =
					'typeId' in options ? options.typeId : this.filters.typeId
				if (typeId) {
					params.typeId = typeId
				}

				try {
					const response = await axios.get(
						generateUrl('/apps/keepiq/api/v1/secrets'),
						{ params },
					)
					this.secrets = response.data.items || []
					this.totalCount = response.data.total || 0
					this.page = response.data.page || 1
				} catch (e) {
					// Resilient offline fallback: if the network is unreachable and
					// a cached snapshot exists, serve the list from it rather than
					// failing (offline-readonly-cache §4.2). Covers the case where
					// the offline flag lagged the actual connectivity loss.
					if (this.isNetworkError(e) && offline.vault) {
						offline.servedFromCache = true
						this.secrets = offline.vault.secrets
						this.totalCount = offline.vault.secrets.length
						this.page = 1
						return
					}
					throw e
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the ENTIRE vault by paging within the server's per-request cap,
		 * accumulating into `this.secrets`. The bulk export/transfer flows need
		 * every secret; a single huge `limit` is rejected by the server (NC caps
		 * a page at a few hundred rows), so we page in chunks of PAGE_SIZE until
		 * the accumulated count reaches the reported total.
		 *
		 * @param {object} options Optional filters ({ folderId, typeId, search }).
		 * @return {Promise<Array<object>>} the full secret list.
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		async fetchAllSecrets(options = {}) {
			// Offline: fetchSecrets already returns the whole cached snapshot.
			const offline = useOfflineStore()
			if (offline.servedFromCache && offline.vault) {
				await this.fetchSecrets({ ...WHOLE_VAULT, ...options, page: 1 })
				return this.secrets
			}

			// Page THROUGH fetchSecrets so it stays the single API/offline seam:
			// each call replaces `this.secrets` with one page and sets totalCount
			// to the full total; we accumulate until we have them all.
			const PAGE_SIZE = 100
			const all = []
			let page = 1
			// Defensive bound; PAGE_SIZE * 100000 covers any realistic vault.
			while (page <= 100000) {
				await this.fetchSecrets({
					...WHOLE_VAULT,
					...options,
					page,
					limit: PAGE_SIZE,
				})
				const batch = this.secrets || []
				all.push(...batch)
				if (batch.length < PAGE_SIZE || all.length >= this.totalCount) {
					break
				}
				page += 1
			}
			this.secrets = all
			this.totalCount = all.length
			this.page = 1
			return all
		},

		/**
		 * Whether an error is a browser network failure (offline).
		 *
		 * @param {Error} e The caught error.
		 * @return {boolean}
		 */
		isNetworkError(e) {
			return (
				!!e
				&& (e.message === 'Network Error'
					|| e.code === 'ERR_NETWORK'
					|| (e.request && !e.response))
			)
		},

		/**
		 * Fetch a single secret and decrypt its encrypted fields in the browser.
		 *
		 * @param {string} id The secret ID.
		 * @return {Promise<object>} The secret with decrypted key/login/additionalFields.
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		async fetchSecret(id) {
			this.loading = true
			try {
				// Offline: open from the cached snapshot's ciphertext (decrypted
				// with the offline-unlocked private key), no server request.
				const offline = useOfflineStore()
				if (offline.servedFromCache && offline.vault) {
					const cached = offline.vault.secrets.find((s) => s.id === id)
					if (cached) {
						this.currentSecret = await this.decryptSecret(cached)
						return this.currentSecret
					}
				}

				try {
					const response = await axios.get(
						generateUrl(`/apps/keepiq/api/v1/secrets/${id}`),
					)
					const secret = response.data
					this.currentSecret = await this.decryptSecret(secret)
					return this.currentSecret
				} catch (e) {
					if (this.isNetworkError(e) && offline.vault) {
						const cached = offline.vault.secrets.find((s) => s.id === id)
						if (cached) {
							offline.servedFromCache = true
							this.currentSecret = await this.decryptSecret(cached)
							return this.currentSecret
						}
					}
					throw e
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Decrypt the encrypted fields of a secret using the session CryptoKey.
		 *
		 * @param {object} secret The secret with ciphertext blobs.
		 * @return {Promise<object>} A copy of the secret with plaintext fields.
		 */
		async decryptSecret(secret) {
			const session = useSessionStore()
			if (!session.cryptoKey) {
				throw new Error('Vault is locked')
			}

			const decrypted = { ...secret }
			if (secret.key) {
				decrypted.key = await rsaDecrypt(secret.key, session.cryptoKey)
			}
			if (secret.login) {
				decrypted.login = await rsaDecrypt(secret.login, session.cryptoKey)
			}
			if (secret.additionalFields) {
				const json = await rsaDecrypt(
					secret.additionalFields,
					session.cryptoKey,
				)
				try {
					decrypted.additionalFields = JSON.parse(json)
				} catch {
					decrypted.additionalFields = json
				}
			}
			return decrypted
		},

		/**
		 * Whether a type id resolves to the `passkey` system type.
		 *
		 * @param {string|null} typeId The secret type id.
		 * @return {boolean}
		 */
		isPasskeyTypeId(typeId) {
			if (!typeId) {
				return false
			}
			const type = useSecretTypeStore().typesById[typeId]
			return Boolean(type) && type.name === PASSKEY_TYPE_NAME
		},

		/**
		 * Create a secret, encrypting the sensitive fields in the browser first.
		 *
		 * @param {object} data Plaintext fields (name, url, key, login, additionalFields, ...).
		 * @return {Promise<object>} The created secret (server response).
		 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
		 */
		async createSecret(data) {
			const session = useSessionStore()
			if (!session.certificate) {
				throw new Error('Vault is locked')
			}
			const publicKey = await importPublicKey(session.certificate)

			// Passkey secrets mirror the RP id into the plaintext `url` so
			// they are matchable/searchable by site (passkey-item-type D3).
			// Only the public RP domain is mirrored — never credential material.
			let url = data.url ?? null
			if (!url && this.isPasskeyTypeId(data.typeId)) {
				url = passkeyRpId(String(data.key ?? '')) ?? null
			}

			const payload = {
				name: data.name,
				url,
				typeId: data.typeId ?? null,
				folderId: data.folderId ?? null,
				key: await rsaEncrypt(String(data.key ?? ''), publicKey),
			}
			if (
				data.login !== undefined
				&& data.login !== null
				&& data.login !== ''
			) {
				payload.login = await rsaEncrypt(String(data.login), publicKey)
			}
			if (data.additionalFields) {
				const json =
					typeof data.additionalFields === 'string'
						? data.additionalFields
						: JSON.stringify(data.additionalFields)
				payload.additionalFields = await rsaEncrypt(json, publicKey)
			}

			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/secrets'),
				payload,
			)
			return response.data
		},

		/**
		 * Update a secret. Sensitive fields are re-encrypted before submission.
		 *
		 * @param {string} id The secret ID.
		 * @param {object} data The fields to change.
		 * @return {Promise<object>} The updated secret (server response).
		 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
		 */
		async updateSecret(id, data) {
			const session = useSessionStore()
			const payload = {}

			for (const field of ['name', 'url', 'typeId', 'folderId']) {
				if (data[field] !== undefined) {
					payload[field] = data[field]
				}
			}

			// Keep the passkey RP-id → url mirror in sync when the credential
			// changes without an explicit url (passkey-item-type D3).
			if (
				data.key !== undefined
				&& data.url === undefined
				&& this.isPasskeyTypeId(data.typeId ?? this.currentSecret?.typeId)
			) {
				const rpId = passkeyRpId(String(data.key ?? ''))
				if (rpId) {
					payload.url = rpId
				}
			}

			if (
				data.key !== undefined
				|| data.login !== undefined
				|| data.additionalFields !== undefined
			) {
				if (!session.certificate) {
					throw new Error('Vault is locked')
				}
				const publicKey = await importPublicKey(session.certificate)
				if (data.key !== undefined) {
					payload.key = await rsaEncrypt(String(data.key), publicKey)
				}
				if (data.login !== undefined) {
					payload.login = data.login
						? await rsaEncrypt(String(data.login), publicKey)
						: null
				}
				if (data.additionalFields !== undefined) {
					const json =
						typeof data.additionalFields === 'string'
							? data.additionalFields
							: JSON.stringify(data.additionalFields)
					payload.additionalFields = await rsaEncrypt(json, publicKey)
				}
			}

			const response = await axios.put(
				generateUrl(`/apps/keepiq/api/v1/secrets/${id}`),
				payload,
			)

			// §11.6 — Sync-on-update: if the owner just changed any
			// sensitive blob (key/login/additionalFields) AND the secret
			// has active shares, re-encrypt the plaintext for every
			// recipient via useShareStore.syncUpdate. The plaintext is
			// the *just-submitted* value (not the freshly returned
			// ciphertext); the share store imports each recipient's
			// public certificate and runs the RSA encryption loop.
			const sensitiveChanged =
				data.key !== undefined
				|| data.login !== undefined
				|| data.additionalFields !== undefined
			if (sensitiveChanged === true) {
				try {
					// Lazy import to avoid a circular dep between the
					// secret and share stores at module load time.
					const { useShareStore } = await import('./share.js')
					const shareStore = useShareStore()
					await shareStore.syncUpdate(
						id,
						{
							key: data.key,
							login: data.login,
							additionalFields:
								data.additionalFields !== undefined
									? typeof data.additionalFields === 'string'
										? data.additionalFields
										: JSON.stringify(data.additionalFields)
									: undefined,
						},
						response.data?.updatedAt
							?? response.data?.updated_at
							?? null,
					)
				} catch (e) {
					// Sync failure should not roll back the owner's
					// update — the owner's copy is already persisted.
					// Surface the error to the share store's `error`
					// field so the sharing sidebar can render a banner.
					// The session encryption flow itself is unaffected.
				}

				// Write-grade team member path (folder-permission-grades
				// §4.2): when the edited row is a recipient COPY and the
				// user holds a write grade on an ancestor team folder,
				// fan the plaintext out to the SOURCE + every recipient.
				try {
					const { useShareStore } = await import('./share.js')
					await useShareStore().syncAsTeamWriter(id, {
						key: data.key,
						login: data.login,
						additionalFields:
							data.additionalFields !== undefined
								? typeof data.additionalFields === 'string'
									? data.additionalFields
									: JSON.stringify(data.additionalFields)
								: undefined,
					})
				} catch (e) {
					// Same fail-soft contract as the owner sync above.
				}
			}

			return response.data
		},

		/**
		 * Delete a secret.
		 *
		 * @param {string} id The secret ID.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-delete-secret
		 */
		async deleteSecret(id) {
			await axios.delete(generateUrl(`/apps/keepiq/api/v1/secrets/${id}`))
			this.secrets = this.secrets.filter((s) => s.id !== id)
		},

		/**
		 * Search secrets by name or url (fuzzy).
		 *
		 * @param {string} term The search term.
		 * @return {Promise<void>}
		 */
		async searchSecrets(term) {
			this.filters.search = term
			this.page = 1
			await this.fetchSecrets({ search: term, page: 1 })
		},
	},
})
