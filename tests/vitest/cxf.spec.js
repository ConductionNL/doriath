/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the FIDO CXF mapping module + parser registration
 * (cxf-import-export §6).
 *
 * Locks down:
 *  - Strict document validation: malformed input fails at parse with a
 *    format-specific error and creates nothing.
 *  - Every supported CXF entity maps to its Keepiq type; passkeys land
 *    on the canonical passkey-item-type schema; unrepresentable entries
 *    are rejected with a reason — never silently dropped.
 *  - Export builds a CXF document from serialized rows; values with no
 *    CXF home land in the unmapped report.
 *  - Export → import round-trips core credentials and folders.
 *
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-cxf-entity-to-keepiq-type-mapping
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-unmapped-item-report
 */

import { describe, expect, it } from 'vitest'
import { buildCxfDocument, cxfToRows, parseCxfDocument } from '../../src/cxf/cxf.js'
import { getParser } from '../../src/import/parserRegistry.js'
import { parsePasskey, serializePasskey } from '../../src/passkey/passkey.js'

import '../../src/import/parsers/index.js'

const SAMPLE_DOC = {
	version: { major: 1, minor: 0 },
	exporter: 'other-vault',
	timestamp: 1750000000,
	accounts: [
		{
			id: 'acc-1',
			userName: 'alice',
			items: [
				{
					id: 'it-1',
					title: 'Example login',
					credentials: [
						{
							type: 'basic-auth',
							urls: ['https://example.com'],
							username: { fieldType: 'string', value: 'alice' },
							password: {
								fieldType: 'concealed-string',
								value: 'pw-placeholder',
							},
						},
					],
				},
				{
					id: 'it-2',
					title: 'Example passkey',
					credentials: [
						{
							type: 'passkey',
							credentialId: 'CXF_CRED_ID_PLACEHOLDER',
							rpId: 'example.com',
							rpName: 'Example',
							userName: 'alice',
							userDisplayName: 'Alice',
							userHandle: 'CXF_HANDLE_PLACEHOLDER',
							key: 'CXF_KEY_PLACEHOLDER',
						},
					],
				},
				{
					id: 'it-3',
					title: 'Example TOTP',
					credentials: [
						{
							type: 'totp',
							url: 'otpauth://totp/x?secret=PLACEHOLDERSEED',
						},
					],
				},
				{
					id: 'it-4',
					title: 'A note',
					credentials: [{ type: 'note', content: 'note body' }],
				},
				{
					id: 'it-5',
					title: 'Deploy key',
					credentials: [
						{
							type: 'ssh-key',
							privateKey: { value: 'SSH_PRIVATE_PLACEHOLDER' },
							publicKey: { value: 'ssh-ed25519 AAAA_PLACEHOLDER' },
						},
					],
				},
				{
					id: 'it-6',
					title: 'Office Wi-Fi',
					credentials: [
						{
							type: 'wifi',
							ssid: 'OfficeNet',
							passphrase: 'wifi-pw-placeholder',
							security: 'WPA2',
						},
					],
				},
				{
					id: 'it-7',
					title: 'Mystery',
					credentials: [{ type: 'quantum-vault-token' }],
				},
				{
					id: 'it-8',
					title: 'Broken passkey',
					credentials: [{ type: 'passkey', rpId: 'partial.example.com' }],
				},
			],
			collections: [{ id: 'col-1', title: 'Work', items: ['it-1', 'it-5'] }],
		},
	],
}

describe('CXF document validation', () => {
	it('rejects non-CXF input with a format-specific error', () => {
		expect(() => parseCxfDocument('not json')).toThrow(/Not a CXF document/)
		expect(() => parseCxfDocument('{}')).toThrow(/missing accounts/)
		expect(() => parseCxfDocument({ accounts: [{}] })).toThrow(/items array/)
	})

	it('is registered as an import parser under id cxf', () => {
		const parser = getParser('cxf')
		expect(parser).toBeDefined()
		expect(parser.label).toMatch(/CXF/)
	})
})

describe('CXF import mapping', () => {
	const rows = cxfToRows(parseCxfDocument(SAMPLE_DOC))
	const byName = Object.fromEntries(rows.map((row) => [row.name, row]))

	it('maps each supported entity to its Keepiq type', () => {
		expect(byName['Example login'].type).toBe('login')
		expect(byName['Example login'].login).toBe('alice')
		expect(byName['Example login'].password).toBe('pw-placeholder')
		expect(byName['Example passkey (passkey)'].type).toBe('passkey')
		expect(byName['Example TOTP (TOTP)'].type).toBe('totp')
		expect(byName['A note'].type).toBe('note')
		expect(byName['Deploy key'].type).toBe('ssh_key')
		expect(byName['Office Wi-Fi'].type).toBe('note')
		expect(byName['Office Wi-Fi'].additionalFields).toEqual({
			ssid: 'OfficeNet',
			security: 'WPA2',
		})
	})

	it('routes passkeys onto the canonical passkey-item-type schema', () => {
		const credential = parsePasskey(byName['Example passkey (passkey)'].password)
		expect(credential).not.toBeNull()
		expect(credential.rpId).toBe('example.com')
		expect(credential.privateKey).toBe('CXF_KEY_PLACEHOLDER')
		expect(byName['Example passkey (passkey)'].url).toBe('example.com')
	})

	it('maps collections to folder paths', () => {
		expect(byName['Example login'].folder).toBe('Work')
		expect(byName['Deploy key'].folder).toBe('Work')
		expect(byName['A note'].folder).toBe('')
	})

	it('rejects unrepresentable and partial entries with reasons — never silently drops', () => {
		expect(byName.Mystery.errors[0]).toMatch(/Unsupported CXF credential type/)
		expect(byName['Broken passkey (passkey)'].errors[0]).toMatch(
			/Incomplete CXF passkey/,
		)
	})
})

describe('CXF export mapping', () => {
	const serialized = [
		{
			name: 'Example login',
			url: 'https://example.com',
			type: 'login',
			login: 'alice',
			password: 'pw-placeholder',
			additionalFields: null,
			folder: 'Work',
		},
		{
			name: 'My passkey',
			url: 'example.com',
			type: 'passkey',
			login: null,
			password: serializePasskey({
				credentialId: 'EXPORT_CRED_ID',
				rpId: 'example.com',
				privateKey: 'EXPORT_KEY',
				counter: 7,
				transports: ['internal'],
			}),
			additionalFields: null,
			folder: '',
		},
		{
			name: 'Root CA',
			url: null,
			type: 'certificate',
			login: null,
			password: 'PEM_PLACEHOLDER',
			additionalFields: null,
			folder: '',
		},
	]

	it('builds a document, reports unmapped values, and preserves folders as collections', () => {
		const { document, unmapped, itemCount } = buildCxfDocument(serialized)
		expect(itemCount).toBe(2)
		expect(document.accounts[0].items).toHaveLength(2)
		expect(document.accounts[0].collections[0].title).toBe('Work')
		// Certificate has no CXF entity; passkey extensions are lossy — both reported.
		expect(unmapped.some((entry) => entry.includes('Root CA'))).toBe(true)
		expect(unmapped.some((entry) => entry.includes('counter/transports'))).toBe(
			true,
		)
	})

	it('round-trips core credentials through export → import', () => {
		const { document } = buildCxfDocument(serialized)
		const rows = cxfToRows(
			parseCxfDocument(JSON.parse(JSON.stringify(document))),
		)
		const login = rows.find((row) => row.type === 'login')
		expect(login.password).toBe('pw-placeholder')
		expect(login.login).toBe('alice')
		expect(login.folder).toBe('Work')
		const passkeyRow = rows.find((row) => row.type === 'passkey')
		const credential = parsePasskey(passkeyRow.password)
		expect(credential.credentialId).toBe('EXPORT_CRED_ID')
		expect(credential.privateKey).toBe('EXPORT_KEY')
		// Extensions default honestly on import (documented lossiness).
		expect(credential.counter).toBe(0)
	})
})
