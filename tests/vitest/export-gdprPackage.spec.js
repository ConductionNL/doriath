/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for GDPR package assembly (`src/export/gdprPackage.js`).
 *
 * Locks down:
 *  - assembleGdprPackage merges metadata + vault into one versioned package.
 *  - The metadata-only variant (locked vault) carries the explicit
 *    "vault not unlocked" limitation section and includesVault=false.
 *
 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	assembleGdprPackage,
	GDPR_PACKAGE_FORMAT,
	VAULT_UNAVAILABLE_REASON,
} from '../../src/export/gdprPackage.js'

const metadata = {
	format: 'keepiq-gdpr-metadata',
	version: 1,
	subject: 'alice',
	suites: [],
	settings: {},
}
const vaultPayload = { secrets: [{ name: 'A' }], folders: [{ path: 'Work' }] }

describe('assembleGdprPackage', () => {
	it('merges metadata + vault into one versioned package', () => {
		const pkg = assembleGdprPackage(metadata, vaultPayload)
		expect(pkg.format).toBe(GDPR_PACKAGE_FORMAT)
		expect(pkg.version).toBe(1)
		expect(pkg.includesVault).toBe(true)
		expect(pkg.metadata).toEqual(metadata)
		expect(pkg.vault.available).toBe(true)
		expect(pkg.vault.secrets).toHaveLength(1)
		expect(pkg.vault.folders).toHaveLength(1)
	})

	it('produces a metadata-only variant with the limitation section when locked', () => {
		const pkg = assembleGdprPackage(metadata, null)
		expect(pkg.includesVault).toBe(false)
		expect(pkg.metadata).toEqual(metadata)
		expect(pkg.vault.available).toBe(false)
		expect(pkg.vault.unavailable).toBe(VAULT_UNAVAILABLE_REASON)
		expect(pkg.vault.secrets).toBeUndefined()
	})
})
