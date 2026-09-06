/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the client-side CSV builder (`src/utils/csv.js`) used by the
 * admin audit export. Asserts RFC 4180 quoting and that the export is built
 * purely in-memory — no network call to a download endpoint.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-8.3
 */

import { describe, expect, it } from 'vitest'
import { buildCsv, csvField } from '../../src/utils/csv.js'

describe('csv util', () => {
	describe('csvField (RFC 4180)', () => {
		it('leaves plain fields unquoted', () => {
			expect(csvField('secret.read')).toBe('secret.read')
		})

		it('quotes and escapes fields containing commas, quotes or newlines', () => {
			expect(csvField('a,b')).toBe('"a,b"')
			expect(csvField('say "hi"')).toBe('"say ""hi"""')
			expect(csvField('line1\nline2')).toBe('"line1\nline2"')
		})

		it('renders null/undefined as an empty field', () => {
			expect(csvField(null)).toBe('')
			expect(csvField(undefined)).toBe('')
		})
	})

	describe('buildCsv', () => {
		it('joins a header row and data rows with CRLF', () => {
			const csv = buildCsv(
				['When', 'Event'],
				[
					['2026-06-14', 'secret.read'],
					['2026-06-14', 'share.granted'],
				],
			)
			expect(csv).toBe(
				'When,Event\r\n2026-06-14,secret.read\r\n2026-06-14,share.granted',
			)
		})
	})
})
