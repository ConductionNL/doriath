/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Phase tests for the three bulk dialogs beside delete: move, share and
 * add-to-team-folder (`src/dialogs/Bulk{Move,Share,TeamFolder}Dialog.vue`).
 *
 * They shared BulkDeleteDialog's confusion between asking and reporting. The
 * host reloads the list when a run completes, which empties the reconciled
 * selection, so what stayed on screen afterwards was a title counting 0
 * secrets, a destination picker, and a live primary button — and pressing it
 * again ran over an empty selection, which REPLACED the report with an empty
 * one. Their run buttons carry static labels, so this was quieter than
 * delete's "Delete 0 secrets" but the same defect.
 *
 * What each dialog pins here: the asking phase, the switch to reporting after
 * a run (outcome title, no picker, no run button, Close promoted), and that a
 * fresh open over a stored report still asks.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BulkMoveDialog from '../../src/dialogs/BulkMoveDialog.vue'
import BulkShareDialog from '../../src/dialogs/BulkShareDialog.vue'
import BulkTeamFolderDialog from '../../src/dialogs/BulkTeamFolderDialog.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

/** The chunked runner awaits per item, so one tick is not enough. */
const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

const STUBS = {
	NcDialog: {
		props: ['name'],
		template:
			'<div><h1 data-testid="title">{{ name }}</h1><slot /><slot name="actions" /></div>',
	},
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcSelect: { template: '<div class="stub-select" />' },
	DestinationSelect: { template: '<div class="stub-picker" />' },
}

const title = (wrapper) => wrapper.find('[data-testid="title"]').text()

/**
 * Mount one of the bulk dialogs with the library chrome stubbed out.
 *
 * @param {object} component The dialog component.
 * @return {object} The wrapper.
 */
function mountDialog(component) {
	return mount(component, { propsData: { open: true }, global: { stubs: STUBS } })
}

// Each dialog: how to make its run button pressable, and the ids/copy that
// tell asking from reporting.
const CASES = [
	{
		name: 'BulkMoveDialog',
		component: BulkMoveDialog,
		runTestid: 'bulk-move-run',
		closeTestid: 'bulk-move-close',
		inputSelector: '.stub-picker',
		askTitle: 'Move {count} secrets',
		reportTitle: 'Moved {ok} of {total} secrets',
		arm: (wrapper) => wrapper.setData({ targetFolderId: 'folder-b' }),
	},
	{
		name: 'BulkShareDialog',
		component: BulkShareDialog,
		runTestid: 'bulk-share-run',
		closeTestid: 'bulk-share-close',
		inputSelector: '[data-testid="bulk-share-recipient"]',
		askTitle: 'Share {count} secrets',
		reportTitle: 'Shared {ok} of {total} secrets',
		arm: (wrapper) => wrapper.setData({ targetUserId: 'bob' }),
	},
	{
		name: 'BulkTeamFolderDialog',
		component: BulkTeamFolderDialog,
		runTestid: 'bulk-team-folder-run',
		closeTestid: 'bulk-team-folder-close',
		inputSelector: '.stub-select',
		askTitle: 'Add {count} secrets to a team folder',
		reportTitle: 'Added {ok} of {total} secrets to the team folder',
		arm: (wrapper) =>
			wrapper.setData({ target: { id: 'tf-1', folderId: 'folder-b' } }),
	},
]

describe.each(CASES)(
	'$name: asking and reporting are separate phases',
	({
		component,
		runTestid,
		closeTestid,
		inputSelector,
		askTitle,
		reportTitle,
		arm,
	}) => {
		let bulk

		beforeEach(() => {
			setActivePinia(createPinia())
			vi.restoreAllMocks()
			bulk = useBulkStore()
			// The per-item work of all three ends in a metadata-only update or
			// a fan-out; neither is what these tests are about.
			vi.spyOn(useSecretStore(), 'updateSecret').mockResolvedValue(undefined)
			vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue({ key: 'k' })
			const teamFolders = useTeamFolderStore()
			vi.spyOn(teamFolders, 'fetchTeamFolders').mockResolvedValue(undefined)
			vi.spyOn(teamFolders, 'runFanOut').mockResolvedValue(undefined)
			// Share resolves the recipient certificate, then registers each
			// re-encrypted copy through the batch endpoint.
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: { certificate: 'cert' },
			})
			vi.spyOn(axios, 'post').mockResolvedValue({
				data: { items: [{ status: 'created' }] },
			})
		})

		it('asks before the run: input, run button, selection-counted title', async () => {
			bulk.setSelection(['a', 'b'])
			const wrapper = mountDialog(component)
			await arm(wrapper)

			expect(title(wrapper)).toBe(askTitle)
			expect(wrapper.find(inputSelector).exists()).toBe(true)
			expect(wrapper.find(`[data-testid="${runTestid}"]`).exists()).toBe(true)
			expect(wrapper.find('[data-testid="bulk-run-panel"]').exists()).toBe(
				false,
			)
		})

		it('reports after the run, with no command affordance left over', async () => {
			bulk.setSelection(['a', 'b'])
			const wrapper = mountDialog(component)
			await arm(wrapper)

			await wrapper.find(`[data-testid="${runTestid}"]`).trigger('click')
			await flushPromises()
			// What the host does on @done: the rows moved, so the selection
			// comes back empty.
			bulk.setSelection([])
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.finished).toBe(true)
			expect(title(wrapper)).toBe(reportTitle)
			expect(wrapper.find(inputSelector).exists()).toBe(false)
			expect(wrapper.find(`[data-testid="${runTestid}"]`).exists()).toBe(false)
			expect(
				wrapper.find(`[data-testid="${closeTestid}"]`).attributes('variant'),
			).toBe('primary')
			expect(wrapper.find('[data-testid="bulk-run-panel"]').exists()).toBe(true)
		})

		it('does not show a previous run\'s report on a fresh open', async () => {
			bulk.report = [{ secretId: 'gone', status: 'ok' }]
			bulk.setSelection(['a'])
			const wrapper = mountDialog(component)
			await arm(wrapper)

			expect(wrapper.vm.finished).toBe(false)
			expect(wrapper.find('[data-testid="bulk-run-panel"]').exists()).toBe(
				false,
			)
			expect(title(wrapper)).toBe(askTitle)
		})
	},
)

// Share resolves the recipient's certificate BEFORE the runner starts, and a
// recipient with no active suite returns early. That dialog never ran, so it
// has to keep asking with the reason on screen — not flip to a report of a
// run that did not happen.
describe('BulkShareDialog: a refused recipient is not a finished run', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('keeps asking when the certificate lookup fails', async () => {
		// A recipient with no active encryption suite: 404 on the certificate.
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { status: 404 } })
		useBulkStore().setSelection(['a'])
		const wrapper = mountDialog(BulkShareDialog)
		await wrapper.setData({ targetUserId: 'nobody' })
		await wrapper.find('[data-testid="bulk-share-run"]').trigger('click')
		await flushPromises()

		expect(wrapper.vm.ran).toBe(false)
		expect(wrapper.vm.finished).toBe(false)
		expect(wrapper.find('[data-testid="bulk-share-run"]').exists()).toBe(true)
		expect(title(wrapper)).toBe('Share {count} secrets')
	})
})
