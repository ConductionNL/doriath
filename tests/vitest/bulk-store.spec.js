/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the bulk store (bulk-actions §8.1): selection semantics
 * (client-only, cleared on lock), the chunked runner's exactly-once
 * report guarantee, cancellation, and retry-failed-only.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useSessionStore } from '../../src/store/modules/session.js'

describe('bulk store: selection', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('deduplicates the selection and counts it', () => {
		const bulk = useBulkStore()
		bulk.setSelection(['a', 'b', 'a', 'c'])
		expect(bulk.selectedIds).toEqual(['a', 'b', 'c'])
		expect(bulk.selectionCount).toBe(3)
	})

	it('clears the selection, report, and progress', () => {
		const bulk = useBulkStore()
		bulk.setSelection(['a'])
		bulk.report = [{ secretId: 'a', status: 'ok' }]
		bulk.clearSelection()
		expect(bulk.selectedIds).toEqual([])
		expect(bulk.report).toEqual([])
		expect(bulk.progress.running).toBe(false)
	})

	it('is cleared when the vault locks and is never persisted', () => {
		const bulk = useBulkStore()
		bulk.registerLockReset()
		bulk.setSelection(['a', 'b'])

		useSessionStore().lock()

		expect(bulk.selectedIds).toEqual([])
		// Client-only: the store never touches browser storage (node env
		// has none — any storage access in the store would have thrown).
		expect(bulk.report).toEqual([])
	})
})

describe('bulk store: chunked runner', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('reports every selected item exactly once, nothing dropped', async () => {
		const bulk = useBulkStore()
		const ids = Array.from({ length: 60 }, (_, i) => `sec-${i}`)

		const report = await bulk.run(
			ids,
			async (id) =>
				id.endsWith('7')
					? (() => {
							throw new Error('boom')
						})()
					: { status: 'ok' },
			'testing',
		)

		expect(report).toHaveLength(60)
		const seen = new Set(report.map((r) => r.secretId))
		expect(seen.size).toBe(60)
		expect(report.filter((r) => r.status === 'failed')).toHaveLength(6)
		expect(report.filter((r) => r.status === 'ok')).toHaveLength(54)
		expect(bulk.progress.running).toBe(false)
	})

	it('cancel stops after the current chunk and reports the rest as skipped', async () => {
		const bulk = useBulkStore()
		const ids = Array.from({ length: 60 }, (_, i) => `sec-${i}`)
		let processed = 0

		const report = await bulk.run(
			ids,
			async () => {
				processed++
				if (processed === 10) {
					bulk.cancel()
				}
				return { status: 'ok' }
			},
			'testing',
		)

		// The first chunk (25) completes; chunks after the cancel are skipped.
		expect(processed).toBe(25)
		expect(report).toHaveLength(60)
		expect(
			report.filter((r) => r.status === 'skipped' && r.reason === 'cancelled'),
		).toHaveLength(35)
	})

	it('retryFailed re-runs ONLY the failed subset and merges the report', async () => {
		const bulk = useBulkStore()
		let failOnce = true

		const perItem = async (id) => {
			if (id === 'bad' && failOnce) {
				failOnce = false
				throw new Error('transient')
			}
			return { status: 'ok' }
		}

		await bulk.run(['good-1', 'bad', 'good-2'], perItem, 'testing')
		expect(bulk.failedItems.map((r) => r.secretId)).toEqual(['bad'])

		const retriedIds = []
		const report = await bulk.retryFailed(async (id) => {
			retriedIds.push(id)
			return perItem(id)
		}, 'retrying')

		expect(retriedIds).toEqual(['bad'])
		expect(report).toHaveLength(3)
		expect(report.every((r) => r.status === 'ok')).toBe(true)
	})

	it('retryFailed with no failures is a no-op', async () => {
		const bulk = useBulkStore()
		await bulk.run(['a'], async () => ({ status: 'ok' }), 'testing')
		const report = await bulk.retryFailed(async () => {
			throw new Error('must not run')
		}, 'retrying')
		expect(report).toHaveLength(1)
		expect(report[0].status).toBe('ok')
	})

	it('deduplicates ids before running', async () => {
		const bulk = useBulkStore()
		const report = await bulk.run(
			['x', 'x', 'y'],
			async () => ({ status: 'ok' }),
			'testing',
		)
		expect(report).toHaveLength(2)
	})
})
