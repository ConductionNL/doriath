/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the Argon2id + AES-256-GCM link-share crypto in
 * `src/crypto/argon2.js`.
 *
 * What these tests lock down:
 *  - `generateLinkPassword()` returns a 20-character password drawn from the
 *    documented alphabet, with reasonable entropy (no repeated calls produce
 *    the same string).
 *  - `isArgon2Supported()` recognises the WebAssembly runtime that Node 22
 *    ships natively.
 *  - `encryptSnapshot()` -> `decryptSnapshot()` round-trips a plaintext JSON
 *    snapshot to exactly the same string when the correct password + salt
 *    are supplied (AES-GCM round-trip + same-KDF symmetry).
 *  - `decryptSnapshot()` THROWS when the password is wrong (AES-GCM
 *    authentication tag is enforced — wrong key => integrity failure,
 *    never silent garbage).
 *  - Two consecutive `encryptSnapshot` calls with the same plaintext +
 *    password produce DIFFERENT blobs and DIFFERENT salts (per-call salt
 *    and IV randomisation).
 *
 * Why these run in the `node` env: the WebCrypto AES-GCM primitives are
 * native on `globalThis.crypto.subtle` in Node 22, and the Argon2 WASM
 * dependency is aliased to a deterministic SHA-512-based stub via
 * `tests/vitest/stubs/argon2-browser.js` (see the vitest.config.js
 * alias map). The real Argon2id KDF is exercised by the existing
 * Playwright e2e suite in the browser. What we are unit-testing here is
 * the AES-GCM round-trip, the salt + IV randomisation, and the
 * password-mismatch tag enforcement — none of which depend on Argon2id
 * specifically, only on the KDF being deterministic in (password, salt).
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13.1
 */

import { describe, expect, it } from 'vitest'
import {
	decryptSnapshot,
	encryptSnapshot,
	generateLinkPassword,
	isArgon2Supported,
} from '../../src/crypto/argon2.js'

describe('generateLinkPassword', () => {
	it('returns a 20-character password drawn from the documented alphabet', () => {
		const password = generateLinkPassword()
		expect(typeof password).toBe('string')
		expect(password).toHaveLength(20)
		// Documented alphabet: A-Z a-z 0-9 ! @ # $ % ^ & * - _ = +
		expect(password).toMatch(/^[A-Za-z0-9!@#$%^&*\-_=+]{20}$/)
	})

	it('produces a different password on each call (entropy sanity check)', () => {
		const a = generateLinkPassword()
		const b = generateLinkPassword()
		const c = generateLinkPassword()
		// Three 20-char draws from a 73-symbol alphabet collide with
		// probability ~10^-37 — any collision is a real entropy bug.
		expect(a).not.toBe(b)
		expect(b).not.toBe(c)
		expect(a).not.toBe(c)
	})
})

describe('isArgon2Supported', () => {
	it('returns true on the Node 22 runtime (WebAssembly is native)', () => {
		expect(isArgon2Supported()).toBe(true)
	})
})

describe('encryptSnapshot / decryptSnapshot round-trip', () => {
	it('round-trips a snapshot to the same plaintext with the correct password', async () => {
		const plaintext = JSON.stringify({
			id: 'secret-42',
			name: 'GitHub PAT',
			login: 'git-user',
			key: 'ghp_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
			additionalFields: { note: 'rotate quarterly' },
		})
		const password = 'test-link-password-XYZ!23'

		const { blob, salt } = await encryptSnapshot(plaintext, password)
		expect(typeof blob).toBe('string')
		expect(typeof salt).toBe('string')
		expect(blob.length).toBeGreaterThan(0)
		expect(salt.length).toBeGreaterThan(0)

		const recovered = await decryptSnapshot(blob, salt, password)
		expect(recovered).toBe(plaintext)
	})

	it('throws when the password is wrong (AES-GCM auth tag mismatch)', async () => {
		const plaintext = JSON.stringify({ id: 'secret-43', value: 'nuclear-codes' })
		const password = 'correct-password-9999'
		const wrongPassword = 'wrong-password-9999'

		const { blob, salt } = await encryptSnapshot(plaintext, password)

		// AES-GCM throws an `OperationError` on tag mismatch — never returns
		// the plaintext that the wrong key would have produced.
		await expect(decryptSnapshot(blob, salt, wrongPassword)).rejects.toThrow()
	})

	it('produces a different ciphertext blob for the same plaintext + password (salt + IV are random)', async () => {
		const plaintext = JSON.stringify({ id: 'secret-44', value: 'same-plain' })
		const password = 'fixed-password-42'

		const first = await encryptSnapshot(plaintext, password)
		const second = await encryptSnapshot(plaintext, password)

		// Salts and IVs are freshly random each call, so the blobs must differ.
		expect(first.blob).not.toBe(second.blob)
		expect(first.salt).not.toBe(second.salt)

		// And BOTH must still decrypt back to the same plaintext.
		expect(await decryptSnapshot(first.blob, first.salt, password)).toBe(
			plaintext,
		)
		expect(await decryptSnapshot(second.blob, second.salt, password)).toBe(
			plaintext,
		)
	})
})
