/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vault-list helpers (restyle Stage 6): the subfolder rows the secret list
 * shows above the secrets (file-manager style), and path labels for the
 * move dialogs' folder pickers so same-named folders at different depths
 * stay distinguishable.
 */

/**
 * Marker prefix for the pseudo-row ids, so a folder row's `id` can never
 * collide with a secret id in CnIndexPage's `rowKey` space.
 *
 * @type {string}
 */
const FOLDER_ROW_PREFIX = 'folder:'

/**
 * The direct subfolders of `selectedFolderId` (or the top-level vaults when
 * null), as list pseudo-rows: filtered by the inline search term
 * (case-insensitive contains, matching the team decision that everything
 * visible in the list is searchable), sorted by name as one group.
 *
 * @param {Array<object>} folders The flat folder list (`{id, name, parentId}`).
 * @param {string|null} selectedFolderId The current folder (null = vault root).
 * @param {string} [searchTerm] The inline search term ('' = no filter).
 * @return {Array<{id: string, folderId: string, name: string, isFolder: true}>}
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */
export function subfolderRows(folders, selectedFolderId, searchTerm = '') {
	const needle = String(searchTerm || '')
		.trim()
		.toLowerCase()
	return (folders || [])
		.filter(
			(folder) =>
				folder
				&& (selectedFolderId
					? folder.parentId === selectedFolderId
					: !folder.parentId),
		)
		.filter(
			(folder) =>
				needle === ''
				|| String(folder.name || '')
					.toLowerCase()
					.includes(needle),
		)
		.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
		.map((folder) => ({
			id: `${FOLDER_ROW_PREFIX}${folder.id}`,
			folderId: folder.id,
			name: folder.name,
			isFolder: true,
			// Vault personalization keys (restyle Stage 9) ride along so the
			// root rows can render the picked icon + color.
			customIcon: folder.customIcon ?? null,
			customColor: folder.customColor ?? null,
		}))
}

/**
 * The "A / B / C" path label of a folder, walked root-first through
 * `parentId`. Guarded against parentId cycles (seen-set) and runaway depth;
 * an unknown id yields ''. Used by the move dialogs so a nested "Production"
 * is distinguishable from a top-level one.
 *
 * @param {Array<object>} folders The flat folder list (`{id, name, parentId}`).
 * @param {string} folderId The folder whose path to render.
 * @return {string} The slash-joined path, deepest folder last.
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
 */
export function folderPathLabel(folders, folderId) {
	const byId = new Map((folders || []).map((f) => [f.id, f]))
	const names = []
	const seen = new Set()
	let current = byId.get(folderId)
	while (current && !seen.has(current.id) && names.length < 32) {
		seen.add(current.id)
		names.unshift(current.name)
		current = current.parentId ? byId.get(current.parentId) : null
	}
	return names.join(' / ')
}

/**
 * The top-level VAULT a folder ultimately lives under, walked up through
 * `parentId`. Powers the vault indicators outside the vault's own page (the
 * All-secrets row dot, the detail sidebar's vault tag): a secret only knows
 * its direct `folderId`, which may be a nested folder. Guarded against
 * parentId cycles (seen-set) like the path walk above; a dangling or
 * unknown id yields null, and so does a cycle that never reaches a root.
 *
 * @param {Array<object>} folders The flat folder list (`{id, name, parentId}`).
 * @param {string|null} folderId The folder to resolve.
 * @return {object|null} The root vault record, or null.
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */
export function rootVaultOf(folders, folderId) {
	const byId = new Map((folders || []).map((f) => [f.id, f]))
	const seen = new Set()
	let current = byId.get(folderId)
	while (current && current.parentId && !seen.has(current.id)) {
		seen.add(current.id)
		current = byId.get(current.parentId)
	}
	return current && !current.parentId ? current : null
}

/**
 * Every vault and folder as a depth-ordered list for the move pickers: a
 * vault, then its own folders beneath it, then the next vault.
 *
 * The pickers used to render the flat store order, which put every vault
 * first and every folder afterwards — so "3 All" and its subfolders sat pages
 * apart and the list read as two unrelated groups. Ordering the tree here
 * means a folder appears under the vault it belongs to, and `depth` lets the
 * picker indent it.
 *
 * `excludeId` drops that entry AND everything under it: a vault cannot
 * receive its own contents, and a folder cannot be moved inside itself.
 *
 * Siblings are sorted by name at every level, matching the nav rail.
 *
 * @param {Array<object>} folders The flat folder list (`{id, name, parentId}`).
 * @param {string|null} [excludeId] Subtree to leave out.
 * @return {Array<object>} Each folder plus a `depth` (0 for a vault).
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */
export function destinationRows(folders, excludeId = null) {
	const byParent = new Map()
	for (const folder of folders || []) {
		const key = folder.parentId ?? null
		if (!byParent.has(key)) {
			byParent.set(key, [])
		}
		byParent.get(key).push(folder)
	}
	for (const siblings of byParent.values()) {
		siblings.sort((a, b) => (a.name || '').localeCompare(b.name || ''))
	}

	const rows = []
	const walk = (parentId, depth) => {
		for (const folder of byParent.get(parentId) ?? []) {
			if (excludeId && folder.id === excludeId) {
				// Skipping without recursing drops the whole subtree.
				continue
			}
			rows.push({ ...folder, depth })
			walk(folder.id, depth + 1)
		}
	}
	walk(null, 0)
	return rows
}
