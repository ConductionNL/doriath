<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Bulk-move dialog (bulk-actions §4): move the selected secrets to a
  target folder via metadata-only updates (no re-encryption). Runs
  through the shared chunked runner with progress + per-item report.

  Two phases in one dialog (see BulkDeleteDialog for the report that started
  this): it ASKS until the run finishes, then it REPORTS. The host reloads
  the list on `done`, which empties the reconciled selection, so a target
  picker and a live Move button left on screen would offer to move nothing —
  and pressing Move again would replace the report with an empty one.

  @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-bulk-move
-->
<template>
	<NcDialog
		:name="title"
		:open="open"
		size="normal"
		data-testid="bulk-move-dialog"
		@update:open="$emit('close')">
		<div class="bulk-move">
			<DestinationSelect
				v-if="!finished"
				v-model="targetFolderId"
				mode="folders"
				:label="t('keepiq', 'Target folder')"
				data-testid="bulk-move-folder" />
			<!-- Gated on THIS dialog's run: the store's report outlives the
			     dialog, so an ungated panel showed the previous run's table on
			     a fresh open. -->
			<BulkRunPanel v-if="ran || bulk.progress.running" @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton
				:variant="finished ? 'primary' : 'tertiary'"
				data-testid="bulk-move-close"
				@click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="!finished"
				variant="primary"
				:disabled="!targetFolderId || bulk.progress.running"
				data-testid="bulk-move-run"
				@click="onRun">
				{{ t('keepiq', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import BulkRunPanel from '../components/BulkRunPanel.vue'
import DestinationSelect from '../components/DestinationSelect.vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'BulkMoveDialog',
	components: {
		BulkRunPanel,
		DestinationSelect,
		NcButton,
		NcDialog,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'done'],
	data() {
		return {
			/**
			 * @type {string|null} The chosen vault or folder; null means nothing
			 * has been picked yet. There is no vault-root destination — a secret
			 * always lives in a vault — so a null value and "unchosen" are the
			 * same state and Move can key off the value directly.
			 */
			targetFolderId: null,
			/** Whether a run was started FROM THIS DIALOG (the store's report outlives it). */
			ran: false,
		}
	},

	computed: {
		bulk() {
			return useBulkStore()
		},

		/**
		 * Whether the dialog has switched from asking to reporting.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		finished() {
			return this.ran && !this.bulk.progress.running
		},

		/**
		 * The dialog title: a command while it asks, an outcome once it
		 * reports — counted off the report, never off the selection, which
		 * the host's post-run reload empties.
		 *
		 * @return {string}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		title() {
			if (this.finished) {
				return this.t('keepiq', 'Moved {ok} of {total} secrets', {
					ok: this.bulk.report.filter((r) => r.status === 'ok').length,
					total: this.bulk.report.length,
				})
			}
			return this.t('keepiq', 'Move {count} secrets', {
				count: this.bulk.selectionCount,
			})
		},
	},

	methods: {
		/**
		 * The per-item move: metadata-only folderId update.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
		 */
		async moveOne(secretId) {
			await useSecretStore().updateSecret(secretId, {
				folderId: this.targetFolderId,
			})
			return { status: 'ok' }
		},

		/**
		 * Run the chunked move over the selection.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async onRun() {
			this.ran = true
			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.moveOne(id),
				this.t('keepiq', 'Moving secrets'),
			)
			this.$emit('done')
		},

		/**
		 * Retry only the failed subset.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async onRetry() {
			await this.bulk.retryFailed(
				(id) => this.moveOne(id),
				this.t('keepiq', 'Retrying move'),
			)
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.bulk-move {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}
</style>
