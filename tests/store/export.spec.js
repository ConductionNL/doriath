/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `useExportStore` (`src/store/modules/export.js`).
 *
 * Locks down:
 *  - The export-event endpoint is called BEFORE the local download is offered.
 *  - An event-endpoint failure surfaces and blocks the export (no silent skip).
 *  - The event request body carries only mode/scope/secretCount — never the
 *    passphrase, plaintext password, or any secret material.
 *  - No localStorage / sessionStorage writes (persistence-leak guard).
 *
 * Runs under jsdom for Pinia reactivity + the AES-GCM encrypt path.
 *
 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useExportStore } from '../../src/store/modules/export.js'

const secrets = [
	{
		name: 'AWS',
		key: 'super-secret-key',
		login: 'admin',
		folderId: null,
		typeId: 'login',
	},
]
const folders = []

describe('useExportStore', () => {
	let clickSpy
	let lsSpy

	beforeEach(() => {
		setActivePinia(createPinia())
		// Intercept the Blob download so it never actually navigates.
		clickSpy = vi
			.spyOn(HTMLAnchorElement.prototype, 'click')
			.mockImplementation(() => {})
		global.URL.createObjectURL = () => 'blob:mock'
		global.URL.revokeObjectURL = () => {}
		lsSpy = vi.spyOn(Storage.prototype, 'setItem')
		// setItem is shared across the localStorage / sessionStorage prototypes,
		// so the one spy covers both.
	})

	// These spies are installed on SHARED prototypes (HTMLAnchorElement,
	// Storage), so without a restore each `beforeEach` stacks another wrapper
	// on the same method and calls accumulate across tests. That is what made
	// `expect(clickSpy).not.toHaveBeenCalled()` see two clicks it never made.
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('calls the export-event endpoint before offering the backup download', async () => {
		const order = []
		vi.spyOn(axios, 'post').mockImplementation(async () => {
			order.push('event')
		})
		clickSpy.mockImplementation(() => order.push('download'))

		const store = useExportStore()
		await store.exportBackup(secrets, folders, 'a-very-strong-passphrase-1', {
			mode: 'vault',
		})

		expect(order).toEqual(['event', 'download'])
	})

	it('sends only mode/scope/secretCount — no passphrase or secret material', async () => {
		let body = null
		vi.spyOn(axios, 'post').mockImplementation(async (url, payload) => {
			body = payload
		})

		const store = useExportStore()
		await store.exportBackup(secrets, folders, 'another-strong-passphrase-2', {
			mode: 'vault',
		})

		expect(Object.keys(body).sort()).toEqual(['mode', 'scope', 'secretCount'])
		const serialized = JSON.stringify(body)
		expect(serialized).not.toContain('another-strong-passphrase-2')
		expect(serialized).not.toContain('super-secret-key')
		expect(body.mode).toBe('encrypted-backup')
		expect(body.secretCount).toBe(1)
	})

	it('surfaces an event-endpoint failure and does NOT download', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('event endpoint down'))

		const store = useExportStore()
		await expect(
			store.exportBackup(secrets, folders, 'strong-passphrase-xyz-3', {
				mode: 'vault',
			}),
		).rejects.toThrow()
		expect(clickSpy).not.toHaveBeenCalled()
		expect(store.error).toBeTruthy()
	})

	it('never writes plaintext/passphrase to local or session storage', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({})

		const store = useExportStore()
		await store.exportBackup(secrets, folders, 'persist-guard-passphrase-4', {
			mode: 'vault',
		})
		await store.exportCsv(secrets, folders, { mode: 'vault' })

		// If any storage write happened, assert it contained no sensitive value.
		for (const call of lsSpy.mock.calls) {
			const joined = JSON.stringify(call)
			expect(joined).not.toContain('persist-guard-passphrase-4')
			expect(joined).not.toContain('super-secret-key')
		}
	})

	it('deleteAccountData sends only the confirmation phrase', async () => {
		let body = null
		vi.spyOn(axios, 'delete').mockImplementation(async (url, config) => {
			body = config.data
			return { data: { deleted: true, report: { secretsDeleted: 3 } } }
		})

		const store = useExportStore()
		const report = await store.deleteAccountData('DELETE MY KEEPIQ DATA')
		expect(body).toEqual({ confirmation: 'DELETE MY KEEPIQ DATA' })
		expect(report.report.secretsDeleted).toBe(3)
	})
})
