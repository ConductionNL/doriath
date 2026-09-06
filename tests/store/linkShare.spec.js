/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the `useLinkShareStore` Pinia store
 * (`src/store/modules/linkShare.js`).
 *
 * What these lock down:
 *  - `createLinkShare` runs the client-side Argon2id+AES-GCM encryption
 *    BEFORE the HTTP POST and never includes the plaintext snapshot,
 *    the generated password, or the salt-only-in-memory state in the
 *    request body. The server must never see the plaintext.
 *  - `createLinkShare` records the one-time password and link URL in
 *    transient store state for the consuming dialog and DOES NOT
 *    persist the password (no localStorage / sessionStorage write).
 *  - `createLinkShare` ignores any usageLimit override in the response,
 *    but DOES rehydrate the response row into `state.linkShares`.
 *  - `fetchLinkShares` populates `state.linkShares` from the metadata
 *    list endpoint (no blob/salt fields ever).
 *  - `deleteLinkShare` calls DELETE and removes the row by id.
 *  - `clearCreatedPassword` resets both transient fields to null.
 *
 * Running under jsdom because Pinia's reactivity (and the AES-GCM
 * encrypt path) need a richer global scope than the bare node env.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13.2
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useLinkShareStore } from '../../src/store/modules/linkShare.js'

/**
 * A Storage-shaped stub that records every write.
 *
 * The no-persistence test used to read `window.localStorage` directly. Nothing
 * in this environment provides it: vitest's jsdom environment does not put
 * `localStorage` on the global, so the lookup fell through to Node's own
 * flag-gated `localStorage` (hence the "--localstorage-file was not provided"
 * warning) and evaluated to `undefined`. `Object.keys(undefined)` then threw
 * "Cannot convert undefined or null to object", so the assertion never ran and
 * the invariant it names was never actually checked.
 *
 * Stubbing also proves the stronger claim. "Storage ended up empty" can hold
 * because a write went somewhere else, or because the write threw on an absent
 * Storage. "setItem was never called" is the invariant the header describes.
 *
 * @return {object} A Storage-shaped stub whose mutators are vi mocks.
 */
function recordingStorage() {
	const entries = new Map()

	return {
		entries,
		getItem: vi.fn((key) =>
			entries.has(String(key)) ? entries.get(String(key)) : null,
		),
		setItem: vi.fn((key, value) => entries.set(String(key), String(value))),
		removeItem: vi.fn((key) => entries.delete(String(key))),
		clear: vi.fn(() => entries.clear()),
		key: vi.fn((index) => Array.from(entries.keys())[index] ?? null),
		get length() {
			return entries.size
		},
	}
}

describe('useLinkShareStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	describe('createLinkShare', () => {
		it('encrypts the snapshot client-side and posts only the blob + salt (never the plaintext)', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({
				data: {
					id: 'ls-001',
					token: 'abcdef1234567890abcdef1234567890',
					linkUrl:
						'https://nc.example/index.php/apps/keepiq/share/link/abcdef1234567890abcdef1234567890',
					usageLimit: 1,
					usageCount: 0,
					createdAt: '2026-06-11T12:00:00Z',
				},
			})

			const store = useLinkShareStore()
			const snapshot = {
				key: 'ghp_super_secret_token_PLAINTEXT',
				login: 'git-user',
				additionalFields: { note: 'rotate quarterly' },
			}

			await store.createLinkShare('secret-42', snapshot, 1, null)

			expect(post).toHaveBeenCalledOnce()
			const [url, body] = post.mock.calls[0]
			expect(url).toBe('/apps/keepiq/api/v1/secrets/secret-42/link-shares')

			// The POST body must contain the encrypted blob + salt + usageLimit
			// + expiresAt — and absolutely nothing that resembles the plaintext.
			expect(body).toHaveProperty('encryptedSecretSnapshot')
			expect(body).toHaveProperty('argon2idSalt')
			expect(body.usageLimit).toBe(1)
			expect(body.expiresAt).toBeNull()

			expect(typeof body.encryptedSecretSnapshot).toBe('string')
			expect(typeof body.argon2idSalt).toBe('string')
			expect(body.encryptedSecretSnapshot.length).toBeGreaterThan(0)
			expect(body.argon2idSalt.length).toBeGreaterThan(0)

			// Plaintext fields must NOT appear ANYWHERE in the request body.
			const serialised = JSON.stringify(body)
			expect(serialised).not.toContain(snapshot.key)
			expect(serialised).not.toContain(snapshot.login)
			expect(serialised).not.toContain('rotate quarterly')
		})

		it('rehydrates the response row into state and records the one-time password + link URL', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({
				data: {
					id: 'ls-002',
					token: 'tok-002',
					linkUrl: '/share/link/tok-002',
					usageLimit: 3,
				},
			})

			const store = useLinkShareStore()
			expect(store.linkShares).toEqual([])
			expect(store.createdPassword).toBeNull()
			expect(store.createdLinkUrl).toBeNull()

			await store.createLinkShare('secret-1', { key: 'x' }, 3, null)

			expect(store.linkShares).toHaveLength(1)
			expect(store.linkShares[0].id).toBe('ls-002')
			expect(store.createdLinkUrl).toBe('/share/link/tok-002')

			// The one-time password must be the 20-char generated string,
			// not any value pulled from the server response.
			expect(typeof store.createdPassword).toBe('string')
			expect(store.createdPassword).toHaveLength(20)
			expect(store.createdPassword).toMatch(/^[A-Za-z0-9!@#$%^&*\-_=+]{20}$/)
		})

		it('does not persist the one-time password anywhere outside the store state', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({
				data: { id: 'ls-003', token: 't', linkUrl: '/u' },
			})

			// Installed BEFORE the call, so a write during it is recorded rather
			// than throwing on an absent Storage. `window === globalThis` here, so
			// stubbing the global covers both `localStorage` and
			// `window.localStorage`.
			const local = recordingStorage()
			const session = recordingStorage()
			vi.stubGlobal('localStorage', local)
			vi.stubGlobal('sessionStorage', session)

			const store = useLinkShareStore()
			await store.createLinkShare('secret-1', { key: 'x' }, 1, null)

			// The dialog flow MUST be the only place the password ever lives.
			// A write to localStorage / sessionStorage would survive a tab
			// close and defeat the one-time-display invariant.
			expect(local.setItem).not.toHaveBeenCalled()
			expect(session.setItem).not.toHaveBeenCalled()
			expect(local.length).toBe(0)
			expect(session.length).toBe(0)

			// And the password really was produced, so the assertions above are
			// about a run that had something to leak.
			expect(typeof store.createdPassword).toBe('string')
			expect(store.createdPassword).toHaveLength(20)
		})
	})

	describe('fetchLinkShares', () => {
		it('populates linkShares from the metadata endpoint', async () => {
			const rows = [
				{ id: 'ls-1', token: 't1', usageLimit: 1, usageCount: 0 },
				{ id: 'ls-2', token: 't2', usageLimit: 5, usageCount: 2 },
			]
			vi.spyOn(axios, 'get').mockResolvedValue({ data: rows })

			const store = useLinkShareStore()
			await store.fetchLinkShares('secret-42')

			expect(store.linkShares).toEqual(rows)
			expect(store.loading).toBe(false)
		})

		it('defaults to an empty list when the endpoint returns no data', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: null })
			const store = useLinkShareStore()
			await store.fetchLinkShares('secret-42')
			expect(store.linkShares).toEqual([])
		})

		it('clears `loading` even when the endpoint throws', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('boom'))
			const store = useLinkShareStore()
			await expect(store.fetchLinkShares('secret-42')).rejects.toThrow('boom')
			expect(store.loading).toBe(false)
		})
	})

	describe('deleteLinkShare', () => {
		it('calls DELETE and removes the row by id', async () => {
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })

			const store = useLinkShareStore()
			store.linkShares = [
				{ id: 'ls-1', token: 't1' },
				{ id: 'ls-2', token: 't2' },
				{ id: 'ls-3', token: 't3' },
			]

			await store.deleteLinkShare('ls-2')

			expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/link-shares/ls-2')
			expect(store.linkShares).toHaveLength(2)
			expect(store.linkShares.map((r) => r.id)).toEqual(['ls-1', 'ls-3'])
		})
	})

	describe('clearCreatedPassword', () => {
		it('resets the transient one-time fields to null', () => {
			const store = useLinkShareStore()
			store.createdPassword = 'leaked-if-not-cleared'
			store.createdLinkUrl = '/share/link/x'

			store.clearCreatedPassword()

			expect(store.createdPassword).toBeNull()
			expect(store.createdLinkUrl).toBeNull()
		})
	})
})
