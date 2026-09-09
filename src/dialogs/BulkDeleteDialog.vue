<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Bulk-delete dialog (bulk-actions §5): an explicit, count-confirmed,
  IRREVERSIBLE hard delete (no trash exists) reusing the per-secret
  cascade; large sets require a typed confirmation. An already-gone
  secret reports skipped, not failed.

  Two phases in one dialog, and the phase is what decides the chrome. Before
  the run it ASKS: warning, typed confirmation, a destructive button counting
  the selection. Once the run has finished it REPORTS: title, per-item table
  and a primary Close, with every command affordance gone. Mixing the two is
  what a tester hit while clearing the dev seed — the host reloads the list
  when the run completes, the reconciled selection is then empty, and the
  dialog sat there offering "Delete 0 secrets" over a finished report.

  @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-bulk-delete
-->
<template>
	<NcDialog
		:name="title"
		:open="open"
		size="normal"
		data-testid="bulk-delete-dialog"
		@update:open="$emit('close')">
		<div class="bulk-delete">
			<NcNoteCard
				v-if="!finished"
				type="warning"
				data-testid="bulk-delete-warning">
				{{
					t(
						'keepiq',
						'This permanently deletes {count} secrets and revokes their shares. There is no trash — this cannot be undone.',
						{ count: bulk.selectionCount },
					)
				}}
			</NcNoteCard>
			<label
				v-if="needsTypedConfirmation && !finished"
				class="bulk-delete__confirm">
				<span>{{
					t('keepiq', 'Type {word} to confirm', { word: confirmWord })
				}}</span>
				<input v-model="typed" type="text" data-testid="bulk-delete-typed" />
			</label>
			<!-- The report lives in the store until the selection is cleared, so
			     it is gated on THIS dialog's own run: without that, opening the
			     dialog again showed the previous run's table before anything
			     had been asked for. -->
			<BulkRunPanel v-if="ran || bulk.progress.running" @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton
				:variant="finished ? 'primary' : 'tertiary'"
				data-testid="bulk-delete-close"
				@click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="!finished"
				variant="error"
				:disabled="!confirmed || bulk.progress.running"
				data-testid="bulk-delete-run"
				@click="onRun">
				{{
					t('keepiq', 'Delete {count} secrets', {
						count: bulk.selectionCount,
					})
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import BulkRunPanel from '../components/BulkRunPanel.vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useSecretStore } from '../store/modules/secret.js'

const TYPED_CONFIRMATION_THRESHOLD = 10

export default {
	name: 'BulkDeleteDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		BulkRunPanel,
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
			typed: '',
			/**
			 * Whether a run was started FROM THIS DIALOG. The store's report
			 * outlives the dialog (it is cleared with the selection), so the
			 * phase cannot be read from the report alone.
			 */
			ran: false,
		}
	},

	computed: {
		bulk() {
			return useBulkStore()
		},

		/**
		 * Whether the dialog has switched from asking to reporting: a run
		 * happened here and is no longer in flight.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		finished() {
			return this.ran && !this.bulk.progress.running
		},

		/**
		 * The dialog title. A command while it still asks, an outcome once it
		 * reports — counted off the report, never off the selection, which the
		 * host's post-run reload empties.
		 *
		 * @return {string}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		title() {
			if (this.finished) {
				return this.t('keepiq', 'Deleted {ok} of {total} secrets', {
					ok: this.bulk.report.filter((r) => r.status === 'ok').length,
					total: this.bulk.report.length,
				})
			}
			return this.t('keepiq', 'Delete {count} secrets', {
				count: this.bulk.selectionCount,
			})
		},

		needsTypedConfirmation() {
			return this.bulk.selectionCount > TYPED_CONFIRMATION_THRESHOLD
		},

		confirmWord() {
			return `DELETE ${this.bulk.selectionCount}`
		},

		confirmed() {
			if (!this.needsTypedConfirmation) {
				return true
			}
			return this.typed === this.confirmWord
		},
	},

	methods: {
		/**
		 * The per-item hard delete; a 404 (already gone) is skipped,
		 * never failed.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>}
		 */
		async deleteOne(secretId) {
			try {
				await useSecretStore().deleteSecret(secretId)
				return { status: 'ok' }
			} catch (e) {
				if (e?.response?.status === 404) {
					return { status: 'skipped', reason: 'already deleted' }
				}
				throw e
			}
		},

		/**
		 * Run the chunked delete over the selection.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async onRun() {
			this.ran = true
			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.deleteOne(id),
				this.t('keepiq', 'Deleting secrets'),
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
				(id) => this.deleteOne(id),
				this.t('keepiq', 'Retrying delete'),
			)
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.bulk-delete {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}

.bulk-delete__confirm {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
