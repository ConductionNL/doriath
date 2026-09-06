/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The root ("All secrets") shows NO vault rows (2026-09-03, per Remko,
 * after comparing with Proton Pass): it is a cross-vault query, not a
 * container the user is inside, so vault rows there were navigation posing
 * as contents — duplicating the nav's folder tree one panel away and
 * pushing every secret below a screen of vaults, worst on mobile where the
 * nav toggle already opens the vault list in one tap. Inside a folder the
 * rows stay: there they ARE the contents of the thing being looked at.
 *
 * Options-object style (like SecretList.registryDispatch.spec.js).
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { describe, expect, it } from 'vitest'
import SecretList from '../../src/views/SecretList.vue'

const folderRows = SecretList.computed.folderRows

const FOLDERS = [
	{ id: 'v-1', name: 'Vault one', parentId: null },
	{ id: 'v-2', name: 'Vault two', parentId: null },
	{ id: 'f-1', name: 'Subfolder', parentId: 'v-1' },
]

describe('SecretList — no vault rows at the root', () => {
	it('renders no folder rows at the root, whatever vaults exist', () => {
		const rows = folderRows.call({
			selectedFolderId: null,
			folders: FOLDERS,
			searchTerm: '',
		})

		expect(rows).toEqual([])
	})

	it('still renders the subfolder rows inside a vault', () => {
		const rows = folderRows.call({
			selectedFolderId: 'v-1',
			folders: FOLDERS,
			searchTerm: '',
		})

		expect(rows.map((row) => row.folderId)).toEqual(['f-1'])
	})
})

// The inverse rule of the strip: WITHOUT vault rows, the cross-vault list
// is the one place a row must say where its secret lives — so the dot
// renders at the root only, and never inside a vault where the page itself
// is the location.
describe('SecretList — vault dot only at the root', () => {
	const rowVault = SecretList.methods.rowVault

	it('resolves a secret in a nested folder to its top-level vault', () => {
		const vault = rowVault.call(
			{ selectedFolderId: null, folders: FOLDERS },
			{ id: 's-1', folderId: 'f-1' },
		)

		expect(vault?.id).toBe('v-1')
	})

	it('passes no vault inside a folder — the page is the location', () => {
		const vault = rowVault.call(
			{ selectedFolderId: 'v-1', folders: FOLDERS },
			{ id: 's-1', folderId: 'f-1' },
		)

		expect(vault).toBeNull()
	})
})
