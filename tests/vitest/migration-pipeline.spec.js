/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the compromise-recovery migration pipeline.
 *
 * What is locked here is the one guarantee the whole change exists to provide:
 * no old ciphertext is ever discarded on the strength of a successful encrypt.
 * The pipeline must decrypt the freshly produced blob back with the NEW private
 * key, byte-compare it against the original, and refuse the record otherwise —
 * leaving the original untouched and the run going.
 *
 * The "old" suite is the primary fixture key pair and the "new" suite is the
 * secondary one, so a record that migrates really does change keys.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */

import { constants, createPrivateKey, privateDecrypt } from 'node:crypto'
import { beforeAll, describe, expect, it } from 'vitest'
import { rsaDecrypt, rsaEncrypt } from '../../src/crypto/rsa.js'
import {
	migrateRecord,
	MIGRATION_STORES,
	reEncryptAttachmentGrant,
	reEncryptSecretFields,
	RoundTripMismatchError,
	verifiedReEncrypt,
} from '../../src/migration/pipeline.js'
import {
	RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM,
	secondaryKeyPair,
	sharedKeyPair,
} from './fixtures/rsa-fixtures.js'

const RSA_BLOCK_SIZE = 512
const RSA_CHUNK_SIZE = 446

/** @type {object} The migration keys: old pair in, new pair out. */
let keys

beforeAll(async () => {
	const oldPair = await sharedKeyPair()
	const newPair = await secondaryKeyPair()

	keys = {
		oldPrivateKey: oldPair.privateKey,
		newPublicKey: newPair.publicKey,
		newPrivateKey: newPair.privateKey,
	}
})

/**
 * Encrypt a value under the OLD suite, i.e. produce the "before" ciphertext.
 *
 * @param {string} plaintext The value to seal.
 * @return {Promise<string>} Ciphertext readable by keys.oldPrivateKey.
 */
async function sealUnderOldSuite(plaintext) {
	const oldPair = await sharedKeyPair()
	return rsaEncrypt(plaintext, oldPair.publicKey)
}

/**
 * Decrypt the chunked wire format with Node's OpenSSL binding — the PHP-side
 * proxy. Concatenates every block before decoding, which is the correct way to
 * reassemble a value whose UTF-8 characters may straddle a chunk boundary.
 *
 * @param {string} ciphertextB64 Base64 chunked ciphertext.
 * @param {string} privateKeyPem PKCS#8 private key PEM.
 * @return {string} The recovered plaintext.
 */
function decryptViaOpenssl(ciphertextB64, privateKeyPem) {
	const raw = Buffer.from(ciphertextB64, 'base64')
	const view = new DataView(raw.buffer, raw.byteOffset, raw.byteLength)
	const chunkCount = view.getUint32(0, false)

	const key = createPrivateKey({ key: privateKeyPem, format: 'pem' })

	const recovered = []
	for (let i = 0; i < chunkCount; i++) {
		const block = raw.subarray(
			4 + i * RSA_BLOCK_SIZE,
			4 + (i + 1) * RSA_BLOCK_SIZE,
		)
		recovered.push(
			privateDecrypt(
				{
					key,
					padding: constants.RSA_PKCS1_OAEP_PADDING,
					oaepHash: 'sha256',
				},
				block,
			),
		)
	}

	return Buffer.concat(recovered).toString('utf8')
}

describe('verifiedReEncrypt — round-trip verification', () => {
	it('re-encrypts under the new key and returns a blob the new key can read', async () => {
		const plaintext = 'correct horse battery staple'
		const before = await sealUnderOldSuite(plaintext)

		const after = await verifiedReEncrypt(before, keys, 'key')

		// Genuinely re-keyed: the new blob differs and the NEW key reads it.
		expect(after).not.toBe(before)
		expect(await rsaDecrypt(after, keys.newPrivateKey)).toBe(plaintext)
	})

	it('rejects a blob that does not decrypt back, and does not touch the original', async () => {
		const plaintext = 'value that must survive a failed migration'
		const before = await sealUnderOldSuite(plaintext)

		// Corrupt the verification by handing the pipeline a "new private key"
		// that does not match the new public key — the exact 2026-07-18 shape,
		// where encryption succeeds and nobody can read the result.
		const mismatched = {
			...keys,
			newPrivateKey: keys.oldPrivateKey,
		}

		await expect(verifiedReEncrypt(before, mismatched, 'key')).rejects.toThrow()

		// The original is still intact and still readable with the old key.
		expect(await rsaDecrypt(before, keys.oldPrivateKey)).toBe(plaintext)
	})

	it('exports a named error type the failure list can key on', () => {
		const error = new RoundTripMismatchError('key')
		expect(error.name).toBe('RoundTripMismatchError')
		expect(error.message).toContain('did not decrypt back')
		// The message is persisted in migration_error and shown to the user, so
		// it must not carry the value it failed on.
		expect(error.message).not.toContain('undefined')
	})
})

describe('reEncryptSecretFields', () => {
	it('re-encrypts key, login and additionalFields', async () => {
		const record = {
			key: await sealUnderOldSuite('s3cret'),
			login: await sealUnderOldSuite('alice@example.org'),
			additionalFields: await sealUnderOldSuite('{"note":"recovery codes"}'),
		}

		const out = await reEncryptSecretFields(record, keys)

		expect(await rsaDecrypt(out.key, keys.newPrivateKey)).toBe('s3cret')
		expect(await rsaDecrypt(out.login, keys.newPrivateKey)).toBe(
			'alice@example.org',
		)
		expect(await rsaDecrypt(out.additionalFields, keys.newPrivateKey)).toBe(
			'{"note":"recovery codes"}',
		)
	})

	it('leaves absent optional fields null rather than encrypting an empty string', async () => {
		const record = {
			key: await sealUnderOldSuite('only-a-key'),
			login: null,
			additionalFields: '',
		}

		const out = await reEncryptSecretFields(record, keys)

		expect(await rsaDecrypt(out.key, keys.newPrivateKey)).toBe('only-a-key')
		expect(out.login).toBeNull()
		expect(out.additionalFields).toBeNull()
	})
})

describe('reEncryptAttachmentGrant', () => {
	it('re-wraps the file key so the new key unwraps the same bytes', async () => {
		// A grant holds the raw AES file key, base64, RSA-wrapped.
		const rawFileKey = crypto.getRandomValues(new Uint8Array(32))
		const fileKeyB64 = btoa(String.fromCharCode(...rawFileKey))

		const record = { wrappedFileKey: await sealUnderOldSuite(fileKeyB64) }

		const out = await reEncryptAttachmentGrant(record, keys)

		const recovered = await rsaDecrypt(out.wrappedFileKey, keys.newPrivateKey)
		expect(recovered).toBe(fileKeyB64)
		expect(Uint8Array.from(atob(recovered), (c) => c.charCodeAt(0))).toEqual(
			rawFileKey,
		)
	})
})

describe('migrateRecord — failure isolation', () => {
	it('reports a per-record failure instead of throwing', async () => {
		const result = await migrateRecord(
			{
				store: MIGRATION_STORES.SECRETS,
				id: 'secret-1',
				// Not valid base64 ciphertext — decryption fails at the crypto layer.
				record: { key: 'not-ciphertext' },
			},
			keys,
		)

		expect(result.ok).toBe(false)
		expect(result.id).toBe('secret-1')
		expect(result.error).toBeTruthy()
	})

	it('refuses an unknown store rather than guessing', async () => {
		const result = await migrateRecord(
			{
				store: 'linkShares',
				id: 'share-1',
				record: {},
			},
			keys,
		)

		expect(result.ok).toBe(false)
		expect(result.error).toContain('Unknown migration store')
	})

	it('a failing record does not stop the records around it', async () => {
		const good = { key: await sealUnderOldSuite('survivor') }

		const results = []
		for (const job of [
			{ store: MIGRATION_STORES.SECRETS, id: 'ok-1', record: good },
			{
				store: MIGRATION_STORES.SECRETS,
				id: 'bad',
				record: { key: 'not-ciphertext' },
			},
			{ store: MIGRATION_STORES.SECRETS, id: 'ok-2', record: good },
		]) {
			results.push(await migrateRecord(job, keys))
		}

		expect(results.map((r) => r.ok)).toEqual([true, false, true])
		expect(await rsaDecrypt(results[0].payload.key, keys.newPrivateKey)).toBe(
			'survivor',
		)
		expect(await rsaDecrypt(results[2].payload.key, keys.newPrivateKey)).toBe(
			'survivor',
		)
	})
})

describe('cross-implementation round-trip (re-chunking against the new key)', () => {
	it('OpenSSL decrypts a migrated multi-chunk value re-chunked under the new key', async () => {
		// Longer than one chunk so the migration must re-chunk against the new
		// key rather than reuse the old framing.
		const plaintext = 'M'.repeat(1200)
		expect(plaintext.length).toBeGreaterThan(RSA_CHUNK_SIZE)

		const before = await sealUnderOldSuite(plaintext)
		const after = await verifiedReEncrypt(before, keys, 'key')

		// The wire header must describe the NEW chunking: ceil(1200 / 446) = 3.
		const raw = Uint8Array.from(atob(after), (c) => c.charCodeAt(0))
		const chunkCount = new DataView(
			raw.buffer,
			raw.byteOffset,
			raw.byteLength,
		).getUint32(0, false)
		expect(chunkCount).toBe(Math.ceil(1200 / RSA_CHUNK_SIZE))

		// PHP/OpenSSL, the server-side reader, recovers it byte-for-byte.
		expect(
			decryptViaOpenssl(after, RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM),
		).toBe(plaintext)
	})

	it('OpenSSL decrypts a migrated non-ASCII value spanning chunk boundaries', async () => {
		// The case that used to be torn by per-chunk UTF-8 decoding: multi-byte
		// characters landing on 446-byte boundaries. If the migration's decrypt
		// step tears them, the round-trip compare fails and the record can never
		// migrate — so this pins the whole path, not just rsaDecrypt.
		const plaintext = 'wachtwoord-€-ü-日本語-🔐-'.repeat(120)
		expect(new TextEncoder().encode(plaintext).length).toBeGreaterThan(
			RSA_CHUNK_SIZE * 3,
		)

		const before = await sealUnderOldSuite(plaintext)
		const after = await verifiedReEncrypt(before, keys, 'additionalFields')

		expect(await rsaDecrypt(after, keys.newPrivateKey)).toBe(plaintext)
		expect(
			decryptViaOpenssl(after, RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM),
		).toBe(plaintext)
	})
})
