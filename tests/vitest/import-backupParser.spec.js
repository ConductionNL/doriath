/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the backup-restore parser + registry (`src/import/`).
 *
 * Locks down:
 *  - The parser is registered in the parser registry on import.
 *  - parseBackup decrypts a real envelope and normalizes rows (export-restore
 *    round-trip).
 *  - A wrong passphrase throws and yields NO rows.
 *
 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
 */

import { describe, expect, it } from 'vitest'
import { encryptBackup } from '../../src/export/backup.js'
import { backupParser, parseBackup } from '../../src/import/backupParser.js'
import { getParser } from '../../src/import/parserRegistry.js'

const payload = {
	format: 'keepiq-vault',
	version: 1,
	secrets: [
		{
			name: 'AWS',
			url: 'https://aws.test',
			login: 'l',
			password: 'k',
			additionalFields: null,
			folder: 'Work',
			type: 'login',
		},
	],
	folders: [{ path: 'Work' }],
}

describe('backup parser registry', () => {
	it('registers the doriath-backup parser on import', () => {
		expect(getParser('doriath-backup')).toBe(backupParser)
		expect(backupParser.requiresPassphrase).toBe(true)
	})

	it('round-trips an encrypted backup into normalized rows', async () => {
		const envelope = await encryptBackup(payload, 'restore-pass-1')
		const rows = await parseBackup(envelope, { passphrase: 'restore-pass-1' })
		expect(rows).toHaveLength(1)
		expect(rows[0]).toMatchObject({
			name: 'AWS',
			login: 'l',
			password: 'k',
			folder: 'Work',
			type: 'login',
		})
	})

	it('throws on a wrong restore passphrase and yields no rows', async () => {
		const envelope = await encryptBackup(payload, 'restore-pass-1')
		await expect(parseBackup(envelope, { passphrase: 'nope' })).rejects.toThrow()
	})
})
