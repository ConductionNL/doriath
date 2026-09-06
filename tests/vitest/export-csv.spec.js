/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for plaintext CSV export + parse (`src/export/csv.js`).
 *
 * Locks down:
 *  - RFC 4180 quoting/escaping (commas, quotes, newlines).
 *  - generateCsv -> parseCsv round-trips cleanly (the layout the
 *    secret-import generic CSV auto-detection consumes).
 *
 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
 */

import { describe, expect, it } from 'vitest'
import { CSV_COLUMNS, generateCsv, parseCsv } from '../../src/export/csv.js'

const secrets = [
	{
		name: 'Simple',
		url: 'https://a.test',
		login: 'u1',
		password: 'p1',
		additionalFields: { notes: 'plain' },
		folder: 'Work',
		type: 'login',
	},
	{
		name: 'Has, comma',
		url: null,
		login: 'u2',
		password: 'has "quote"',
		additionalFields: 'line1\nline2',
		folder: 'Work/Sub',
		type: 'login',
	},
]

describe('generateCsv', () => {
	it('emits the fixed header row', () => {
		const csv = generateCsv(secrets)
		expect(csv.split('\r\n')[0]).toBe(CSV_COLUMNS.join(','))
	})

	it('quotes fields containing commas, quotes, and newlines per RFC 4180', () => {
		const csv = generateCsv(secrets)
		expect(csv).toContain('"Has, comma"')
		expect(csv).toContain('"has ""quote"""')
		expect(csv).toContain('"line1\nline2"')
	})
})

describe('generateCsv -> parseCsv round-trip', () => {
	it('reproduces the secrets after a parse', () => {
		const csv = generateCsv(secrets)
		const rows = parseCsv(csv)
		expect(rows).toHaveLength(2)
		expect(rows[0].name).toBe('Simple')
		expect(rows[0].folder).toBe('Work')
		expect(rows[1].name).toBe('Has, comma')
		expect(rows[1].password).toBe('has "quote"')
		expect(rows[1].notes).toBe('line1\nline2')
		expect(rows[1].folder).toBe('Work/Sub')
	})
})
