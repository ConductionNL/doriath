/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The round-trip byte-compare, tested by forcing a silent mismatch.
 *
 * A mismatch where the new blob decrypts *successfully* but to different bytes
 * cannot be produced with real keys — WebCrypto would throw first. It is also
 * the dangerous case: encryption reported success, decryption reported success,
 * and the value is silently wrong. The 2026-07-18 incident was exactly this
 * shape. So the crypto layer is mocked here to inject it, in its own spec file
 * so the real-crypto tests in migration-pipeline.spec.js stay unmocked.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Values rsaDecrypt hands back, in call order. The pipeline calls it twice per
 * field: once for the original, once to verify the re-encrypted blob.
 *
 * @type {string[]}
 */
let decryptQueue = []

vi.mock('../../src/crypto/rsa.js', () => ({
	rsaEncrypt: vi.fn(async () => 'NEW-CIPHERTEXT'),
	rsaDecrypt: vi.fn(async () => {
		if (decryptQueue.length === 0) {
			throw new Error('rsaDecrypt called more times than the test queued')
		}
		return decryptQueue.shift()
	}),
}))

const {
	MIGRATION_STORES,
	RoundTripMismatchError,
	verifiedReEncrypt,
	migrateRecord,
} = await import('../../src/migration/pipeline.js')

const KEYS = {
	oldPrivateKey: 'old-private',
	newPublicKey: 'new-public',
	newPrivateKey: 'new-private',
}

beforeEach(() => {
	decryptQueue = []
})

describe('round-trip byte-compare', () => {
	it('throws RoundTripMismatchError when the verify decrypt yields different bytes', async () => {
		// Original decrypts to one value; the re-encrypted blob decrypts back to
		// another. Both crypto calls "succeed".
		decryptQueue = ['the real password', 'a different password']

		await expect(
			verifiedReEncrypt('OLD-CIPHERTEXT', KEYS, 'key'),
		).rejects.toThrow(RoundTripMismatchError)
	})

	it('throws on a mismatch that differs only in trailing bytes', async () => {
		// Written as an escape, not a literal control character: a raw NUL in
		// the source makes git treat this whole file as binary, so the diff
		// becomes unreviewable — and an invisible byte is a poor thing to hang
		// an assertion on.
		decryptQueue = ['secret', 'secret\u0000']

		await expect(
			verifiedReEncrypt('OLD-CIPHERTEXT', KEYS, 'key'),
		).rejects.toThrow(RoundTripMismatchError)
	})

	it('throws on a mismatch that differs only by a replacement character', async () => {
		// The exact shape of a torn multi-byte UTF-8 character.
		decryptQueue = ['café', 'caf\uFFFD\uFFFD']

		await expect(
			verifiedReEncrypt('OLD-CIPHERTEXT', KEYS, 'key'),
		).rejects.toThrow(RoundTripMismatchError)
	})

	it('accepts a byte-identical round-trip', async () => {
		decryptQueue = ['identical', 'identical']

		await expect(verifiedReEncrypt('OLD-CIPHERTEXT', KEYS, 'key')).resolves.toBe(
			'NEW-CIPHERTEXT',
		)
	})

	it('a mismatch halts the run and is NOT reportable as unrecoverable', async () => {
		decryptQueue = ['the real password', 'corrupted']

		const result = await migrateRecord(
			{
				store: MIGRATION_STORES.SECRETS,
				id: 'secret-1',
				record: { key: 'OLD-CIPHERTEXT' },
			},
			KEYS,
		)

		expect(result.ok).toBe(false)
		expect(result.payload).toBeUndefined()
		expect(result.error).toContain('did not decrypt back')

		// The decisive assertions. Step 1 succeeded, so the STORED value is
		// readable — the fault is the new key, not the data. Marking this
		// permanent would let the server finalise a perfectly good secret as
		// unrecoverable and lock the user out of it.
		expect(result.permanent).toBe(false)
		expect(result.halt).toBe(true)
		expect(result.phase).toBe('verify')
	})

	it('an old-key decrypt failure is permanent and does not halt the run', async () => {
		// Empty queue makes the first rsaDecrypt throw — i.e. the existing
		// ciphertext will not open with the key we hold.
		decryptQueue = []

		const result = await migrateRecord(
			{
				store: MIGRATION_STORES.SECRETS,
				id: 'secret-1',
				record: { key: 'OLD-CIPHERTEXT' },
			},
			KEYS,
		)

		expect(result.ok).toBe(false)
		expect(result.permanent).toBe(true)
		expect(result.halt).toBe(false)
		expect(result.phase).toBe('decrypt-old')
		expect(result.error).toContain(
			'could not be decrypted with the previous key',
		)
	})

	it('defaults to NOT permanent for an unclassified failure', async () => {
		// An unknown store never touches crypto: nothing proven about the data,
		// so it must not cost the user access.
		const result = await migrateRecord(
			{
				store: 'linkShares',
				id: 'share-1',
				record: {},
			},
			KEYS,
		)

		expect(result.ok).toBe(false)
		expect(result.permanent).toBe(false)
		expect(result.halt).toBe(false)
	})
})
