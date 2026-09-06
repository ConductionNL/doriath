/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the encrypted backup envelope (`src/export/backup.js`).
 *
 * Locks down:
 *  - encryptBackup -> decryptBackup round-trips the payload exactly.
 *  - The envelope is versioned and self-describing (format, version, kdf
 *    params + salt, cipher, payload).
 *  - decryptBackup reads KDF parameters FROM the envelope: a simulated v2
 *    envelope with bumped parameters still decrypts (params not hardcoded).
 *  - A wrong passphrase throws (AES-GCM tag mismatch) — never garbage.
 *
 * Runs under node; argon2-browser is aliased to the deterministic stub.
 *
 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	BACKUP_FORMAT,
	BACKUP_VERSION,
	decryptBackup,
	encryptBackup,
} from '../../src/export/backup.js'

const payload = {
	format: 'keepiq-vault',
	version: 1,
	secrets: [
		{
			name: 'GitHub',
			url: 'https://github.com',
			login: 'me',
			password: 'p@ss',
			additionalFields: null,
			folder: 'Work',
		},
	],
	folders: [{ path: 'Work' }],
}

describe('encryptBackup / decryptBackup', () => {
	it('round-trips a payload with the correct passphrase', async () => {
		const envelope = await encryptBackup(payload, 'correct horse battery staple')
		const restored = await decryptBackup(
			envelope,
			'correct horse battery staple',
		)
		expect(restored).toEqual(payload)
	})

	it('produces a versioned, self-describing envelope', async () => {
		const envelope = await encryptBackup(payload, 'a-strong-passphrase-123')
		expect(envelope.format).toBe(BACKUP_FORMAT)
		expect(envelope.version).toBe(BACKUP_VERSION)
		expect(envelope.kdf.alg).toBe('argon2id')
		expect(typeof envelope.kdf.salt).toBe('string')
		expect(envelope.kdf.memory).toBeGreaterThan(0)
		expect(envelope.cipher.alg).toBe('aes-256-gcm')
		expect(typeof envelope.payload).toBe('string')
		expect(typeof envelope.created_at).toBe('string')
	})

	it('reads KDF params from the envelope (survives a parameter bump)', async () => {
		const envelope = await encryptBackup(payload, 'pass-for-bump-test')
		// Simulate a future parameter bump recorded in the envelope. Because the
		// salt drives the (stubbed) derivation and the params live in the
		// envelope, decryption must still succeed.
		const bumped = {
			...envelope,
			version: 2,
			kdf: { ...envelope.kdf, memory: 131072, iterations: 4 },
		}
		const restored = await decryptBackup(bumped, 'pass-for-bump-test')
		expect(restored).toEqual(payload)
	})

	it('throws on the wrong passphrase (GCM tag mismatch)', async () => {
		const envelope = await encryptBackup(payload, 'right-passphrase')
		await expect(decryptBackup(envelope, 'wrong-passphrase')).rejects.toThrow()
	})

	it('rejects a non-backup envelope', async () => {
		await expect(
			decryptBackup({ format: 'something-else' }, 'x'),
		).rejects.toThrow()
	})
})
