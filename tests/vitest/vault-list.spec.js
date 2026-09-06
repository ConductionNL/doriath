/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the vault-list helpers (restyle Stage 6): the subfolder pseudo-
 * rows the secret list shows above the secrets, and the "A / B / C" path
 * labels the move dialogs use to disambiguate nested folders.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { describe, expect, it } from 'vitest'
import {
	folderPathLabel,
	rootVaultOf,
	subfolderRows,
} from '../../src/utils/vaultList.js'

const FOLDERS = [
	{ id: 'a', name: 'Alpha vault', parentId: null },
	{ id: 'b', name: 'Beta vault', parentId: null },
	{ id: 'a1', name: 'Nested', parentId: 'a' },
	{ id: 'a2', name: 'Another nested', parentId: 'a' },
	{ id: 'a1x', name: 'Deep', parentId: 'a1' },
]

describe('subfolderRows', () => {
	it('lists the top-level vaults at the root, name-sorted as one group', () => {
		const rows = subfolderRows(FOLDERS, null)
		expect(rows.map((r) => r.name)).toEqual(['Alpha vault', 'Beta vault'])
	})

	it("lists only the selected folder's DIRECT children", () => {
		const rows = subfolderRows(FOLDERS, 'a')
		expect(rows.map((r) => r.folderId)).toEqual(['a2', 'a1'])
	})

	it('shapes pseudo-rows so they can never collide with secret ids', () => {
		const [row] = subfolderRows(FOLDERS, 'a1')
		expect(row).toEqual({
			id: 'folder:a1x',
			folderId: 'a1x',
			name: 'Deep',
			isFolder: true,
			customIcon: null,
			customColor: null,
		})
	})

	it('carries the vault customization keys through (restyle Stage 9)', () => {
		const folders = [
			{
				id: 'v1',
				name: 'Styled',
				parentId: null,
				customIcon: 'briefcase',
				customColor: 'blue',
			},
			{ id: 'v2', name: 'Plain', parentId: null },
		]
		const rows = subfolderRows(folders, null)
		expect(rows.find((r) => r.folderId === 'v1')).toMatchObject({
			customIcon: 'briefcase',
			customColor: 'blue',
		})
		// Unset stays an explicit null, never undefined.
		expect(rows.find((r) => r.folderId === 'v2')).toMatchObject({
			customIcon: null,
			customColor: null,
		})
	})

	it('filters by the inline search term, case-insensitively', () => {
		expect(subfolderRows(FOLDERS, 'a', 'NEST').map((r) => r.folderId)).toEqual([
			'a2',
			'a1',
		])
		expect(
			subfolderRows(FOLDERS, 'a', 'another').map((r) => r.folderId),
		).toEqual(['a2'])
		expect(subfolderRows(FOLDERS, 'a', 'no-match')).toEqual([])
	})

	it('is safe on empty and missing input', () => {
		expect(subfolderRows([], null)).toEqual([])
		expect(subfolderRows(undefined, 'a')).toEqual([])
	})
})

describe('rootVaultOf', () => {
	it('walks a nested folder up to its top-level vault', () => {
		expect(rootVaultOf(FOLDERS, 'a1x')?.id).toBe('a')
		expect(rootVaultOf(FOLDERS, 'a1')?.id).toBe('a')
	})

	it('returns a vault as its own root', () => {
		expect(rootVaultOf(FOLDERS, 'b')?.id).toBe('b')
	})

	it('yields null for unknown or missing ids', () => {
		expect(rootVaultOf(FOLDERS, 'nope')).toBeNull()
		expect(rootVaultOf(FOLDERS, null)).toBeNull()
		expect(rootVaultOf([], 'a')).toBeNull()
	})

	it('yields null for a dangling parent and for a parentId cycle', () => {
		const dangling = [{ id: 'x', name: 'X', parentId: 'ghost' }]
		expect(rootVaultOf(dangling, 'x')).toBeNull()

		const cycle = [
			{ id: 'p', name: 'P', parentId: 'q' },
			{ id: 'q', name: 'Q', parentId: 'p' },
		]
		expect(rootVaultOf(cycle, 'p')).toBeNull()
	})
})

describe('folderPathLabel', () => {
	it('renders the root-first path of a nested folder', () => {
		expect(folderPathLabel(FOLDERS, 'a1x')).toBe('Alpha vault / Nested / Deep')
		expect(folderPathLabel(FOLDERS, 'b')).toBe('Beta vault')
	})

	it('yields the empty string for an unknown id', () => {
		expect(folderPathLabel(FOLDERS, 'nope')).toBe('')
	})

	it('terminates on a corrupt parentId cycle', () => {
		const cyclic = [
			{ id: 'x', name: 'X', parentId: 'y' },
			{ id: 'y', name: 'Y', parentId: 'x' },
		]
		expect(folderPathLabel(cyclic, 'x')).toBe('Y / X')
	})
})
