/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for client-side master-password re-auth (`src/crypto/reauth.js`).
 *
 * Locks down:
 *  - The correct password verifies (decrypts the private-key blob -> true).
 *  - A wrong password returns false (never throws, never touches session state).
 *  - Empty inputs fail closed.
 *
 * Uses the real WebCrypto AES path (native in Node 22) to build a private-key
 * blob with encryptPrivateKey, then verifies via verifyMasterPassword.
 *
 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
 */

import { describe, expect, it } from 'vitest'
import { encryptPrivateKey } from '../../src/crypto/aes.js'
import { verifyMasterPassword } from '../../src/crypto/reauth.js'

const PEM = '-----BEGIN PRIVATE KEY-----\nMOCKKEYDATA\n-----END PRIVATE KEY-----'

describe('verifyMasterPassword', () => {
	it('returns true for the correct master password', async () => {
		const blob = await encryptPrivateKey(PEM, 'master-pass-123')
		expect(await verifyMasterPassword(blob, 'master-pass-123')).toBe(true)
	})

	it('returns false for a wrong password without throwing', async () => {
		const blob = await encryptPrivateKey(PEM, 'master-pass-123')
		expect(await verifyMasterPassword(blob, 'wrong-pass')).toBe(false)
	})

	it('fails closed on empty inputs', async () => {
		expect(await verifyMasterPassword('', 'x')).toBe(false)
		expect(await verifyMasterPassword('blob', '')).toBe(false)
		expect(await verifyMasterPassword(null, 'x')).toBe(false)
	})
})
