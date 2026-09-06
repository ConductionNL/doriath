/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the canonical passkey credential module (`src/passkey/`)
 * and the Bitwarden fido2Credentials import mapping.
 *
 * Locks down:
 *  - Canonical JSON serialize/parse round-trips all fields.
 *  - Malformed/partial credentials yield null (explicit invalid state) —
 *    never fabricated fields.
 *  - Bitwarden `login.fido2Credentials[]` entries map to `passkey` rows
 *    with the credential JSON in `password` and the RP id mirrored into
 *    `url`; a partial entry is rejected with a reason.
 *
 * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-canonical-cxf-aligned-passkey-credential-schema
 * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-passkey-creation-via-api-and-bitwarden-import
 */

import { describe, expect, it } from 'vitest'
import { parseBitwardenJson } from '../../src/import/parsers/bitwarden.js'
import {
	buildPasskeyCredential,
	parsePasskey,
	passkeyRpId,
	serializePasskey,
	truncateCredentialId,
} from '../../src/passkey/passkey.js'

const FULL_CREDENTIAL = {
	credentialId: 'BASE64URL_CREDENTIAL_ID_HERE',
	rpId: 'example.com',
	rpName: 'Example',
	userName: 'alice',
	userDisplayName: 'Alice Example',
	userHandle: 'BASE64URL_USER_HANDLE_HERE',
	privateKey: 'BASE64URL_PRIVATE_KEY_HERE',
	algorithm: -7,
	counter: 0,
	transports: ['internal', 'hybrid'],
	createdAt: '2026-07-01T00:00:00.000Z',
}

describe('canonical passkey schema', () => {
	it('serialize/parse round-trips every canonical field', () => {
		const json = serializePasskey(FULL_CREDENTIAL)
		expect(json).toBeTypeOf('string')
		const parsed = parsePasskey(json)
		expect(parsed).toEqual(FULL_CREDENTIAL)
	})

	it('defaults only the documented extension fields, never credential material', () => {
		const minimal = buildPasskeyCredential({
			credentialId: 'ID',
			rpId: 'example.com',
			privateKey: 'KEY',
		})
		expect(minimal.counter).toBe(0)
		expect(minimal.transports).toEqual([])
		expect(minimal.algorithm).toBe(-7)
		expect(minimal.createdAt).toBeTypeOf('string')
		expect(minimal.userName).toBe('')
	})

	it('yields null for malformed JSON and for missing required fields — never fabricated fields', () => {
		expect(parsePasskey('not-json')).toBeNull()
		expect(parsePasskey('')).toBeNull()
		expect(parsePasskey('{"rpId":"example.com"}')).toBeNull()
		expect(
			serializePasskey({ credentialId: 'ID', rpId: 'example.com' }),
		).toBeNull()
		expect(buildPasskeyCredential(null)).toBeNull()
	})

	it('extracts the RP id for the plaintext url mirror', () => {
		const json = serializePasskey(FULL_CREDENTIAL)
		expect(passkeyRpId(json)).toBe('example.com')
		expect(passkeyRpId('garbage')).toBeNull()
	})

	it('truncates long credential ids for display', () => {
		expect(truncateCredentialId('short')).toBe('short')
		expect(truncateCredentialId('ABCDEFGHIJKLMNOP')).toBe('ABCDEFGHIJKL…')
	})
})

describe('Bitwarden fido2Credentials import', () => {
	const bitwardenExport = {
		folders: [],
		items: [
			{
				type: 1,
				name: 'Example login',
				login: {
					username: 'alice',
					password: 'hunter2-placeholder',
					uris: [{ uri: 'https://example.com' }],
					fido2Credentials: [
						{
							credentialId: 'BW_CRED_ID_PLACEHOLDER',
							rpId: 'example.com',
							rpName: 'Example',
							userName: 'alice',
							userDisplayName: 'Alice Example',
							userHandle: 'BW_USER_HANDLE_PLACEHOLDER',
							keyValue: 'BW_KEY_VALUE_PLACEHOLDER',
							counter: '0',
							creationDate: '2026-07-01T00:00:00.000Z',
						},
						{
							// Partial: no key material — must be rejected.
							credentialId: 'BW_PARTIAL_ID_PLACEHOLDER',
							rpId: 'partial.example.com',
						},
					],
				},
			},
		],
	}

	it('maps a complete entry to a passkey row with JSON in password and rpId in url', () => {
		const rows = parseBitwardenJson(bitwardenExport)
		const passkeyRows = rows.filter((row) => row.type === 'passkey')
		expect(passkeyRows).toHaveLength(2)

		const complete = passkeyRows.find((row) => row.errors.length === 0)
		expect(complete).toBeDefined()
		expect(complete.url).toBe('example.com')
		expect(complete.name).toBe('Example login (passkey)')
		const credential = parsePasskey(complete.password)
		expect(credential.privateKey).toBe('BW_KEY_VALUE_PLACEHOLDER')
		expect(credential.userHandle).toBe('BW_USER_HANDLE_PLACEHOLDER')
		expect(credential.createdAt).toBe('2026-07-01T00:00:00.000Z')
	})

	it('rejects a partial entry with a reason instead of creating a partial passkey', () => {
		const rows = parseBitwardenJson(bitwardenExport)
		const rejected = rows.find(
			(row) => row.type === 'passkey' && row.errors.length > 0,
		)
		expect(rejected).toBeDefined()
		expect(rejected.errors[0]).toMatch(/Incomplete Bitwarden passkey/)
	})

	it('keeps the parent login row importing normally alongside its passkeys', () => {
		const rows = parseBitwardenJson(bitwardenExport)
		const loginRow = rows.find((row) => row.type === 'login')
		expect(loginRow).toBeDefined()
		expect(loginRow.errors).toHaveLength(0)
		expect(loginRow.password).toBe('hunter2-placeholder')
	})
})
