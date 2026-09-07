/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/BulkDeleteDialog.vue`.
 *
 * The dialog is two phases in one surface, and what these tests pin is the
 * boundary between them. A tester clearing the dev seed hit the failure: the
 * host reloads the list when the run finishes, the reconciled selection is
 * then EMPTY, and the dialog kept offering "Delete 0 secrets" — a command
 * button over a finished report, next to a warning about deleting 0 secrets.
 *
 *  - Before the run it asks: warning, destructive run button, counted title.
 *  - Once the run has finished it reports: outcome title counted off the
 *    REPORT (not the emptied selection), no warning, no run button, Close
 *    promoted to primary.
 *  - The store's report outlives the dialog, so a freshly opened dialog must
 *    not show the previous run's table before anything has been asked for.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-bulk-delete
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BulkDeleteDialog from '../../src/dialogs/BulkDeleteDialog.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

/**
 * Mount the dialog with the library chrome stubbed out. NcDialog's `name` is
 * rendered into the stub so the title is assertable.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(BulkDeleteDialog, {
		propsData: { open: true },
		global: {
			stubs: {
				NcDialog: {
					props: ['name'],
					template:
						'<div><h1 data-testid="title">{{ name }}</h1><slot /><slot name="actions" /></div>',
				},
				NcNoteCard: { template: '<div class="note"><slot /></div>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
			},
		},
	})
}

const title = (wrapper) => wrapper.find('[data-testid="title"]').text()

/** The chunked runner awaits per item, so one tick is not enough. */
const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Press the destructive button and let the whole chunked run settle, then
 * empty the selection the way the host's post-run reload does.
 *
 * @param {object} wrapper The mounted dialog.
 * @param {object} bulk The bulk store.
 * @return {Promise<void>}
 */
async function runAndReload(wrapper, bulk) {
	await wrapper.find('[data-testid="bulk-delete-run"]').trigger('click')
	await flushPromises()
	bulk.setSelection([])
	await wrapper.vm.$nextTick()
}

describe('BulkDeleteDialog', () => {
	let bulk

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		bulk = useBulkStore()
		vi.spyOn(useSecretStore(), 'deleteSecret').mockResolvedValue(undefined)
	})

	it('asks before the run: warning, run button, selection-counted title', () => {
		bulk.setSelection(['a', 'b', 'c'])
		const wrapper = mountDialog()

		expect(title(wrapper)).toBe('Delete {count} secrets')
		expect(wrapper.find('[data-testid="bulk-delete-warning"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="bulk-delete-run"]').exists()).toBe(true)
	})

	// The complaint this dialog was fixed for: after the run the host reloads,
	// the selection reconciles to empty, and every command affordance that
	// stayed on screen then counted 0.
	it('reports after the run, with no command affordance left over', async () => {
		bulk.setSelection(['a', 'b', 'c'])
		const wrapper = mountDialog()

		// runAndReload also does what the host does on @done: the deleted rows
		// are gone, so the selection comes back empty.
		await runAndReload(wrapper, bulk)

		expect(title(wrapper)).toBe('Deleted {ok} of {total} secrets')
		expect(wrapper.vm.finished).toBe(true)
		expect(wrapper.find('[data-testid="bulk-delete-run"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="bulk-delete-warning"]').exists()).toBe(
			false,
		)
		expect(
			wrapper.find('[data-testid="bulk-delete-close"]').attributes('variant'),
		).toBe('primary')
	})

	// The outcome is counted off the report, so a partial run still reads
	// honestly once the selection behind it is gone.
	it('counts the outcome off the report, not the selection', async () => {
		useSecretStore().deleteSecret.mockRejectedValueOnce(
			new Error('permission denied'),
		)
		bulk.setSelection(['a', 'b', 'c'])
		const wrapper = mountDialog()

		await runAndReload(wrapper, bulk)

		expect(bulk.report).toHaveLength(3)
		expect(bulk.failedItems).toHaveLength(1)
		// The stub returns the key, so assert the interpolation inputs instead.
		expect(wrapper.vm.finished).toBe(true)
		expect(bulk.report.filter((r) => r.status === 'ok')).toHaveLength(2)
	})

	// The report is stored, not per-dialog: it survives until the selection is
	// cleared. A dialog reopened over a previous run's report must still ASK.
	it('does not show a previous run\'s report on a fresh open', () => {
		bulk.report = [{ secretId: 'gone', status: 'ok' }]
		bulk.setSelection(['a'])
		const wrapper = mountDialog()

		expect(wrapper.find('[data-testid="bulk-run-panel"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="bulk-delete-run"]').exists()).toBe(true)
		expect(title(wrapper)).toBe('Delete {count} secrets')
	})

	it('runs the delete over the whole selection', async () => {
		bulk.setSelection(['a', 'b'])
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="bulk-delete-run"]').trigger('click')
		await flushPromises()

		expect(useSecretStore().deleteSecret).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('done')).toBeTruthy()
	})
})
