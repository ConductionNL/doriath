/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for the emergency-access recovery-envelope crypto
 * (src/crypto/emergencyEnvelope.js).
 *
 * Locks the zero-knowledge contract: the grantor's private key is hybrid-
 * encrypted to the grantee's public certificate; only the grantee's own private
 * key opens it; the raw grantor key never appears in the serialized envelope
 * (only ciphertext is sent to the server); a non-grantee cannot recover it.
 *
 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
 */

import { describe, expect, it } from 'vitest'
import {
	buildRecoveryEnvelope,
	ENVELOPE_VERSION,
	openRecoveryEnvelope,
} from '../../src/crypto/emergencyEnvelope.js'
import { secondaryKeyPair, sharedKeyPair } from './fixtures/rsa-fixtures.js'

// A stand-in grantor private-key PEM (the envelope treats it as opaque bytes).
const GRANTOR_PRIVATE_KEY_PEM =
	'-----BEGIN PRIVATE KEY-----\n'
	+ 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC-GRANTOR-SECRET-KEY-MATERIAL\n'
	+ '-----END PRIVATE KEY-----'

describe('emergency recovery envelope', () => {
	it('builds an envelope the grantee can open, recovering the grantor key', async () => {
		const grantee = await sharedKeyPair()

		const envelopeJson = await buildRecoveryEnvelope(
			GRANTOR_PRIVATE_KEY_PEM,
			grantee.publicKeyPem,
		)
		const envelope = JSON.parse(envelopeJson)
		expect(envelope.v).toBe(ENVELOPE_VERSION)
		expect(envelope.encKey).toBeTruthy()
		expect(envelope.iv).toBeTruthy()
		expect(envelope.ct).toBeTruthy()

		const recovered = await openRecoveryEnvelope(
			envelopeJson,
			grantee.privateKey,
		)
		expect(recovered).toBe(GRANTOR_PRIVATE_KEY_PEM)
	})

	it('never puts the raw grantor key (or its distinctive material) in the envelope', async () => {
		const grantee = await sharedKeyPair()
		const envelopeJson = await buildRecoveryEnvelope(
			GRANTOR_PRIVATE_KEY_PEM,
			grantee.publicKeyPem,
		)

		expect(envelopeJson).not.toContain('GRANTOR-SECRET-KEY-MATERIAL')
		expect(envelopeJson).not.toContain('BEGIN PRIVATE KEY')
	})

	it('cannot be opened by a non-grantee (wrong private key)', async () => {
		const grantee = await sharedKeyPair()
		const attacker = await secondaryKeyPair()

		const envelopeJson = await buildRecoveryEnvelope(
			GRANTOR_PRIVATE_KEY_PEM,
			grantee.publicKeyPem,
		)

		await expect(
			openRecoveryEnvelope(envelopeJson, attacker.privateKey),
		).rejects.toThrow()
	})

	it('rejects a malformed envelope', async () => {
		const grantee = await sharedKeyPair()
		await expect(
			openRecoveryEnvelope(JSON.stringify({ v: 99 }), grantee.privateKey),
		).rejects.toThrow()
	})
})
