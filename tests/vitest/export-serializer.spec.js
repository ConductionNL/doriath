/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the vault serializer (`src/export/serializer.js`).
 *
 * Locks down:
 *  - Whole-vault serialization with relative folder paths.
 *  - Folder-scoped serialization includes only the selected subtree's secrets.
 *
 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
 */

import { describe, expect, it } from 'vitest'
import { PAYLOAD_FORMAT, serializeVault } from '../../src/export/serializer.js'

const folders = [
	{ id: 'f1', name: 'Work', parentId: null },
	{ id: 'f2', name: 'Cloud', parentId: 'f1' },
	{ id: 'f3', name: 'Personal', parentId: null },
]

const secrets = [
	{ name: 'AWS', key: 'k1', login: 'l1', folderId: 'f2', typeId: 'login' },
	{ name: 'Bank', key: 'k2', login: 'l2', folderId: 'f3', typeId: 'login' },
	{ name: 'Root', key: 'k3', login: null, folderId: null, typeId: 'login' },
]

describe('serializeVault', () => {
	it('serializes the whole vault with relative folder paths', () => {
		const payload = serializeVault(secrets, folders, { mode: 'vault' })
		expect(payload.format).toBe(PAYLOAD_FORMAT)
		expect(payload.secrets).toHaveLength(3)
		const aws = payload.secrets.find((s) => s.name === 'AWS')
		expect(aws.folder).toBe('Work/Cloud')
		expect(aws.password).toBe('k1')
		const root = payload.secrets.find((s) => s.name === 'Root')
		expect(root.folder).toBe('')
		expect(payload.folders.map((f) => f.path).sort()).toEqual([
			'Personal',
			'Work',
			'Work/Cloud',
		])
	})

	it('folder-scoped export includes only the selected subtree', () => {
		// Selecting "Work" (f1) must include its subtree (f2/Cloud) secrets.
		const payload = serializeVault(secrets, folders, {
			mode: 'folders',
			folderIds: ['f1'],
		})
		expect(payload.secrets.map((s) => s.name)).toEqual(['AWS'])
		expect(payload.folders.map((f) => f.path).sort()).toEqual([
			'Work',
			'Work/Cloud',
		])
	})
})
