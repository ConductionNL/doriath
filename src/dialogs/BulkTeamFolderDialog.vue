<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Bulk add-to-team-folder dialog (bulk-actions §6.2): move the selected
  secrets into a team folder's tree (metadata-only), then run the
  existing idempotent team-folder fan-out so every member receives a
  re-encrypted copy — resume/retry never double-shares.

  Two phases in one dialog (see BulkDeleteDialog for the report that started
  this): it ASKS until the run finishes, then it REPORTS. The host reloads
  the list on `done`, which empties the reconciled selection, so a folder
  picker and a live Add button left on screen would offer to add nothing —
  and pressing Add again would replace the report with an empty one. Here the
  run is two steps, so the switch waits for the membership fan-out as well.

  @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
-->
<template>
	<NcDialog
		:name="title"
		:open="open"
		size="normal"
		data-testid="bulk-team-folder-dialog"
		@update:open="$emit('close')">
		<div class="bulk-tf">
			<NcSelect
				v-if="!finished"
				v-model="target"
				:options="teamFolderOptions"
				:inputLabel="t('keepiq', 'Team folder')"
				label="label"
				data-testid="bulk-team-folder-select" />
			<p v-if="fanOut.running" data-testid="bulk-tf-fanout">
				{{
					t('keepiq', 'Fanning out to members — {done} / {total}', {
						done: fanOut.done,
						total: fanOut.total,
					})
				}}
			</p>
			<!-- Gated on THIS dialog's run: the store's report outlives the
			     dialog, so an ungated panel showed the previous run's table on
			     a fresh open. -->
			<BulkRunPanel v-if="ran || bulk.progress.running" @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton
				:variant="finished ? 'primary' : 'tertiary'"
				data-testid="bulk-team-folder-close"
				@click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="!finished"
				variant="primary"
				:disabled="!target || bulk.progress.running || fanOut.running"
				data-testid="bulk-team-folder-run"
				@click="onRun">
				{{ t('keepiq', 'Add to team folder') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import BulkRunPanel from '../components/BulkRunPanel.vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useTeamFolderStore } from '../store/modules/teamFolder.js'

export default {
	name: 'BulkTeamFolderDialog',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
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
			target: null,
			/** Whether a run was started FROM THIS DIALOG (the store's report outlives it). */
			ran: false,
		}
	},

	computed: {
		bulk() {
			return useBulkStore()
		},

		teamFolderStore() {
			return useTeamFolderStore()
		},

		fanOut() {
			return this.teamFolderStore.fanOut
		},

		/**
		 * Whether the dialog has switched from asking to reporting. The run is
		 * two steps here — the chunked move, then the membership fan-out — and
		 * neither may still be in flight.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		finished() {
			return this.ran && !this.bulk.progress.running && !this.fanOut.running
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
				return this.t(
					'keepiq',
					'Added {ok} of {total} secrets to the team folder',
					{
						ok: this.bulk.report.filter((r) => r.status === 'ok').length,
						total: this.bulk.report.length,
					},
				)
			}
			return this.t('keepiq', 'Add {count} secrets to a team folder', {
				count: this.bulk.selectionCount,
			})
		},

		teamFolderOptions() {
			const folderNames = Object.fromEntries(
				useFolderStore().folders.map((f) => [f.id, f.name]),
			)
			return this.teamFolderStore.owned.map((tf) => ({
				id: tf.id,
				folderId: tf.folderId,
				label: folderNames[tf.folderId] || tf.folderId,
			}))
		},
	},

	async mounted() {
		try {
			await this.teamFolderStore.fetchTeamFolders()
		} catch {
			// Surfaced via store state.
		}
	},

	methods: {
		/**
		 * The per-item step: metadata-only move into the team folder.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>}
		 */
		async moveOne(secretId) {
			await useSecretStore().updateSecret(secretId, {
				folderId: this.target.folderId,
			})
			return { status: 'ok' }
		},

		/**
		 * Move the selection into the team folder, then run the
		 * idempotent membership fan-out once for the whole folder.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
		 */
		async onRun() {
			this.ran = true
			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.moveOne(id),
				this.t('keepiq', 'Adding to team folder'),
			)
			try {
				await this.teamFolderStore.runFanOut(this.target.id)
			} catch {
				// Fan-out errors surface via the team-folder store; the
				// idempotent reconcile picks up missing pairs on retry.
			}
			this.$emit('done')
		},

		/**
		 * Retry only the failed moves, then re-run the fan-out (the
		 * reconcile step makes the re-run a no-op for shared pairs).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async onRetry() {
			await this.bulk.retryFailed(
				(id) => this.moveOne(id),
				this.t('keepiq', 'Retrying'),
			)
			try {
				await this.teamFolderStore.runFanOut(this.target.id)
			} catch {
				// See onRun.
			}
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.bulk-tf {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}
</style>
