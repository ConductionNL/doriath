/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the secret-import format parsers (`src/import/parsers/`).
 * Runs under jsdom so the KeePass XML parser's DOMParser is available; the CSV,
 * Bitwarden, and Passwords parsers are environment-agnostic.
 *
 * Locks down per format: header auto-detection + remap (CSV), login vs
 * non-login items + TOTP + collections (Bitwarden), nested groups + custom
 * strings + History-ignored (KeePass XML), folders + custom fields (Nextcloud
 * Passwords), KDBX magic-byte detection, and the normalized-row contract.
 *
 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-supported-import-formats
 */

import { describe, expect, it } from 'vitest'
import { dedupeKey, isKdbx, normalizeUrl } from '../../src/import/model.js'
import {
	parseBitwardenCsv,
	parseBitwardenJson,
} from '../../src/import/parsers/bitwarden.js'
import { detectMapping, parseCsvImport } from '../../src/import/parsers/csv.js'
import { parseKeepassXml } from '../../src/import/parsers/keepassXml.js'
import { parseNcPasswords } from '../../src/import/parsers/ncPasswords.js'

describe('CSV parser', () => {
	it('auto-detects common headers case-insensitively', () => {
		const mapping = detectMapping([
			'Title',
			'Website',
			'Username',
			'Password',
			'Notes',
			'Group',
		])
		const targets = Object.fromEntries(mapping.map((m) => [m.column, m.target]))
		expect(targets.Title).toBe('name')
		expect(targets.Website).toBe('url')
		expect(targets.Username).toBe('login')
		expect(targets.Password).toBe('password')
		expect(targets.Group).toBe('folder')
		expect(targets.Notes).toBe('notes')
	})

	it('parses quoted/escaped fields and folder paths', () => {
		const csv =
			'name,url,username,password,folder\r\n'
			+ '"GitHub, Inc.",https://github.com,octocat,"hun""ter2",Work/CI\r\n'
		const { rows } = parseCsvImport(csv)
		expect(rows).toHaveLength(1)
		expect(rows[0].name).toBe('GitHub, Inc.')
		expect(rows[0].password).toBe('hun"ter2')
		expect(rows[0].folder).toBe('Work/CI')
		expect(rows[0].errors).toHaveLength(0)
	})

	it('rejects a row with a missing name but keeps the others', () => {
		const csv = 'name,password\r\nKept,k1\r\n,k2\r\n'
		const { rows } = parseCsvImport(csv)
		expect(rows[0].name).toBe('Kept')
		expect(rows[0].errors).toHaveLength(0)
		expect(rows[1].errors).toContain('Missing name')
	})

	it('applies an explicit remap (password column corrected)', () => {
		const csv = 'name,secretvalue\r\nThing,topsecret\r\n'
		const mapping = [
			{ column: 'name', target: 'name' },
			{ column: 'secretvalue', target: 'password' },
		]
		const { rows } = parseCsvImport(csv, { mapping })
		expect(rows[0].password).toBe('topsecret')
	})
})

describe('Bitwarden JSON parser', () => {
	const fixture = {
		folders: [{ id: 'f1', name: 'Work/CI' }],
		collections: [{ id: 'c1', name: 'Shared' }],
		items: [
			{
				type: 1,
				name: 'GitHub',
				folderId: 'f1',
				notes: 'a note',
				login: {
					username: 'octocat',
					password: 'hunter2',
					uri: 'https://github.com',
					totp: 'otpauth://x',
				},
			},
			{
				type: 1,
				name: 'SharedThing',
				collectionIds: ['c1'],
				login: { username: 'u', password: 'p' },
			},
			{ type: 2, name: 'A secure note', secureNote: { type: 0 } },
		],
	}

	it('maps login items incl. TOTP + folder, rejects non-login items', () => {
		const rows = parseBitwardenJson(fixture)
		expect(rows).toHaveLength(3)
		const gh = rows[0]
		expect(gh.name).toBe('GitHub')
		expect(gh.login).toBe('octocat')
		expect(gh.password).toBe('hunter2')
		expect(gh.url).toBe('https://github.com')
		expect(gh.folder).toBe('Work/CI')
		expect(gh.additionalFields.totp).toBe('otpauth://x')
		expect(gh.additionalFields.notes).toBe('a note')
		// Collection name resolves to folder.
		expect(rows[1].folder).toBe('Shared')
		// Non-login item rejected.
		expect(rows[2].errors.join(' ')).toMatch(/secure note/i)
	})

	it('parses Bitwarden CSV via the fixed header mapping', () => {
		const csv =
			'name,login_uri,login_username,login_password,notes,login_totp,folder,type\r\n'
			+ 'Item,https://x.test,user,pass,note1,seed1,Work,login\r\n'
		const rows = parseBitwardenCsv(csv)
		expect(rows[0].name).toBe('Item')
		expect(rows[0].login).toBe('user')
		expect(rows[0].password).toBe('pass')
		expect(rows[0].additionalFields.totp).toBe('seed1')
	})
})

describe('KeePass XML parser', () => {
	const xml = `<?xml version="1.0"?>
<KeePassFile><Root>
  <Group><Name>Database</Name>
    <Entry>
      <String><Key>Title</Key><Value>RootEntry</Value></String>
      <String><Key>Password</Key><Value>rootpass</Value></String>
      <History>
        <Entry><String><Key>Password</Key><Value>OLD-SHOULD-IGNORE</Value></String></Entry>
      </History>
    </Entry>
    <Group><Name>Work</Name>
      <Group><Name>CI</Name>
        <Entry>
          <String><Key>Title</Key><Value>Jenkins</Value></String>
          <String><Key>UserName</Key><Value>ci</Value></String>
          <String><Key>Password</Key><Value>cipass</Value></String>
          <String><Key>URL</Key><Value>https://ci.test</Value></String>
          <String><Key>CustomToken</Key><Value>tok123</Value></String>
        </Entry>
      </Group>
    </Group>
  </Group>
</Root></KeePassFile>`

	it('parses entries with group hierarchy as folder paths and ignores History', () => {
		const rows = parseKeepassXml(xml)
		expect(rows).toHaveLength(2)
		const root = rows.find((r) => r.name === 'RootEntry')
		expect(root.folder).toBe('')
		expect(root.password).toBe('rootpass')
		// The History value must NOT leak.
		expect(JSON.stringify(rows)).not.toContain('OLD-SHOULD-IGNORE')

		const jenkins = rows.find((r) => r.name === 'Jenkins')
		expect(jenkins.folder).toBe('Work/CI')
		expect(jenkins.login).toBe('ci')
		expect(jenkins.url).toBe('https://ci.test')
		expect(jenkins.additionalFields.CustomToken).toBe('tok123')
	})

	it('throws when the KeePass root element is missing', () => {
		expect(() => parseKeepassXml('<NotKeePass/>')).toThrow(/KeePassFile/)
	})
})

describe('Nextcloud Passwords parser', () => {
	const backup = {
		folders: [
			{
				id: 'fa',
				label: 'Work',
				parent: '00000000-0000-0000-0000-000000000000',
			},
			{ id: 'fb', label: 'CI', parent: 'fa' },
		],
		passwords: [
			{
				label: 'Jenkins',
				url: 'https://ci.test',
				username: 'ci',
				password: 'cipass',
				notes: 'ci notes',
				folder: 'fb',
				customFields: JSON.stringify([
					{ label: 'PIN', type: 'text', value: '1234' },
				]),
			},
		],
	}

	it('maps fields + resolves folder hierarchy + custom fields', () => {
		const rows = parseNcPasswords(backup)
		expect(rows).toHaveLength(1)
		expect(rows[0].name).toBe('Jenkins')
		expect(rows[0].login).toBe('ci')
		expect(rows[0].password).toBe('cipass')
		expect(rows[0].folder).toBe('Work/CI')
		expect(rows[0].additionalFields.notes).toBe('ci notes')
		expect(rows[0].additionalFields.PIN).toBe('1234')
	})

	it('throws on a non-Passwords file', () => {
		expect(() => parseNcPasswords({ foo: 1 })).toThrow(/Passwords/)
	})
})

describe('KDBX detection + dedupe helpers', () => {
	it('detects the KDBX magic bytes', () => {
		expect(isKdbx(new Uint8Array([0x9a, 0xa2, 0xd9, 0x03, 0x00]))).toBe(true)
		expect(isKdbx(new Uint8Array([0x7b, 0x22, 0x61]))).toBe(false)
	})

	it('normalizes urls scheme/trailing-slash-insensitively', () => {
		expect(normalizeUrl('https://github.com/')).toBe('github.com')
		expect(normalizeUrl('HTTP://GitHub.com')).toBe('github.com')
		expect(normalizeUrl('')).toBe('')
	})

	it('matches duplicates on normalized name + url, both-empty too', () => {
		expect(dedupeKey({ name: 'GitHub', url: 'https://github.com/' })).toBe(
			dedupeKey({ name: ' github ', url: 'http://github.com' }),
		)
		expect(dedupeKey({ name: 'X', url: '' })).toBe(
			dedupeKey({ name: 'x', url: null }),
		)
	})
})
