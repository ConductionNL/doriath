/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The detail sidebar's vault tag (2026-09-03, per Remko — Proton's pattern):
 * the rows in All secrets carry only a compact dot, and the one place you
 * look at a single secret names its vault under the title, with the vault's
 * own Stage 9 icon and color. This pins the resolution: a secret in a
 * nested folder resolves to its TOP-LEVEL vault, and the tag stays away
 * while nothing is loaded or the folder tree has no match.
 *
 * Options-object style (like SecretList.registryDispatch.spec.js): the
 * sidebar's full mount drags in decryption, shares and attachments — none
 * of which the tag touches.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'

const vault = SecretDetailSidebar.computed.vault

describe('SecretDetailSidebar — vault tag resolution', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		useFolderStore().folders = [
			{ id: 'v-1', name: 'Keepiq', parentId: null, customColor: 'orange' },
			{ id: 'f-1', name: 'Nested', parentId: 'v-1' },
		]
	})

	it('resolves a secret in a nested folder to its top-level vault', () => {
		const resolved = vault.call({
			secret: { id: 's-1', folderId: 'f-1' },
		})

		expect(resolved).toMatchObject({ id: 'v-1', name: 'Keepiq' })
	})

	it('resolves a secret sitting directly in a vault to that vault', () => {
		const resolved = vault.call({
			secret: { id: 's-1', folderId: 'v-1' },
		})

		expect(resolved?.id).toBe('v-1')
	})

	it('yields null while nothing is loaded or the tree has no match', () => {
		expect(vault.call({ secret: null })).toBeNull()
		expect(vault.call({ secret: { id: 's-1', folderId: 'ghost' } })).toBeNull()
	})
})
