/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Additional fields survive REAL encryption, with real keys.
 *
 * The store spec mocks `rsaEncrypt` as ENC(<input>), which is fine for asserting
 * which field the members land in but proves nothing about secrecy — the mock's
 * "ciphertext" contains its own plaintext. This spec uses the actual RSA-4096-OAEP
 * path so two claims can be made honestly:
 *
 *   1. what was typed is what decrypts (the acceptance criterion), and
 *   2. the ciphertext really does not carry the member names or values in the clear.
 *
 * The member NAMES matter as much as the values here. They are inside the blob by
 * design, precisely so a server cannot learn that a secret has a `recovery-codes`
 * field — the existence of the field is itself information.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 */

import { beforeAll, describe, expect, it } from 'vitest'
import { rsaDecrypt, rsaEncrypt } from '../../src/crypto/rsa.js'
import {
	membersToObject,
	objectToMembers,
} from '../../src/utils/additionalFields.js'
import { sharedKeyPair } from './fixtures/rsa-fixtures.js'

/** @type {object} A real RSA-4096 pair. */
let pair

beforeAll(async () => {
	pair = await sharedKeyPair()
})

describe('additional fields under real encryption', () => {
	it('round-trips every member name and value exactly as entered', async () => {
		const members = [
			{ name: 'client-id', value: 'acme-4711' },
			{ name: 'recovery-codes', value: 'a1b2 c3d4 e5f6' },
			// Awkward but legal input: spaces, punctuation, unicode, an equals sign
			// that a naive serializer might mangle.
			{ name: 'note (staging)', value: 'wachtwoord=hüpfer & co' },
		]

		const sealed = await rsaEncrypt(
			JSON.stringify(membersToObject(members)),
			pair.publicKey,
		)
		const opened = objectToMembers(
			JSON.parse(await rsaDecrypt(sealed, pair.privateKey)),
		)

		expect(opened).toEqual(members)
	})

	it('leaks neither member names nor values into the ciphertext', async () => {
		const sealed = await rsaEncrypt(
			JSON.stringify(
				membersToObject([{ name: 'recovery-codes', value: 'a1b2c3' }]),
			),
			pair.publicKey,
		)

		expect(sealed).not.toContain('recovery-codes')
		expect(sealed).not.toContain('a1b2c3')
	})

	it('an empty blob is still ciphertext, and decrypts to no members', async () => {
		// Removing the last member sends `{}`. It must be sealed like any other blob
		// — an empty value stored in the clear would say "this secret has no
		// additional fields" to anyone reading the database.
		const sealed = await rsaEncrypt(
			JSON.stringify(membersToObject([])),
			pair.publicKey,
		)

		expect(sealed).not.toBe('{}')
		expect(sealed.length).toBeGreaterThan(0)
		expect(
			objectToMembers(JSON.parse(await rsaDecrypt(sealed, pair.privateKey))),
		).toEqual([])
	})
})
