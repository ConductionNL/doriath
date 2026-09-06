/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Route ↔ detail-sidebar mapping (restyle Stage 8): opening a secret keeps
 * the list context (root or folder) in the path, closing drops only the
 * `:id` segment, and only the two list routes ever host the sidebar.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
 */

import { describe, expect, it } from 'vitest'
import {
	activeDetailSecretId,
	closeDetailLocation,
	secretDetailLocation,
} from '../../src/utils/detailRoute.js'

describe('secretDetailLocation', () => {
	it('opens a secret from the root list on the root route', () => {
		expect(
			secretDetailLocation({ name: 'SecretList', params: {} }, 's-1'),
		).toEqual({ name: 'SecretList', params: { id: 's-1' } })
	})

	it('keeps the folder context when opening from a folder view', () => {
		expect(
			secretDetailLocation(
				{ name: 'SecretListFolder', params: { folderId: 'f-9' } },
				's-1',
			),
		).toEqual({
			name: 'SecretListFolder',
			params: { folderId: 'f-9', id: 's-1' },
		})
	})

	it('falls back to the root list when the route carries no folder', () => {
		expect(
			secretDetailLocation({ name: 'Dashboard', params: {} }, 's-1'),
		).toEqual({ name: 'SecretList', params: { id: 's-1' } })
	})

	it('replaces an already-open secret instead of nesting', () => {
		expect(
			secretDetailLocation(
				{ name: 'SecretListFolder', params: { folderId: 'f-9', id: 's-1' } },
				's-2',
			),
		).toEqual({
			name: 'SecretListFolder',
			params: { folderId: 'f-9', id: 's-2' },
		})
	})
})

describe('closeDetailLocation', () => {
	it('returns to the root list from a root-hosted sidebar', () => {
		expect(
			closeDetailLocation({ name: 'SecretList', params: { id: 's-1' } }),
		).toEqual({ name: 'SecretList' })
	})

	it('returns to the folder view from a folder-hosted sidebar', () => {
		expect(
			closeDetailLocation({
				name: 'SecretListFolder',
				params: { folderId: 'f-9', id: 's-1' },
			}),
		).toEqual({ name: 'SecretListFolder', params: { folderId: 'f-9' } })
	})
})

describe('activeDetailSecretId', () => {
	it('reads the open secret id from the root list route', () => {
		expect(
			activeDetailSecretId({ name: 'SecretList', params: { id: 's-1' } }),
		).toBe('s-1')
	})

	it('reads the open secret id from the folder route', () => {
		expect(
			activeDetailSecretId({
				name: 'SecretListFolder',
				params: { folderId: 'f-9', id: 's-1' },
			}),
		).toBe('s-1')
	})

	it('is null while no id segment is present', () => {
		expect(activeDetailSecretId({ name: 'SecretList', params: {} })).toBeNull()
	})

	it('never opens for other id-carrying routes (ApplicationDetail)', () => {
		expect(
			activeDetailSecretId({
				name: 'ApplicationDetail',
				params: { id: 'app-1' },
			}),
		).toBeNull()
	})

	it('is null for a missing route', () => {
		expect(activeDetailSecretId(null)).toBeNull()
	})
})
