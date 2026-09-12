<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Bulk-share dialog (bulk-actions §6.1): share the selected secrets
  with one recipient via the per-secret RSA fan-out — decrypt with the
  owner's in-session CryptoKey, re-encrypt under the recipient's
  certificate (WebCrypto, ADR-003), and register through the idempotent
  batch endpoint. No plaintext ever leaves the browser; a recipient
  without an active suite fails fast before any work runs.

  Two phases in one dialog (see BulkDeleteDialog for the report that started
  this): it ASKS until the run finishes, then it REPORTS. The host reloads
  the list on `done`, which empties the reconciled selection, so a recipient
  field and a live Share button left on screen would offer to share nothing —
  and pressing Share again would replace the report with an empty one.

  @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-bulk-share
-->
<template>
	<NcDialog
		:name="title"
		:open="open"
		size="normal"
		data-testid="bulk-share-dialog"
		@update:open="$emit('close')">
		<div class="bulk-share">
			<label v-if="!finished" class="bulk-share__field">
				<span>{{ t('keepiq', 'Recipient user ID') }}</span>
				<input
					v-model="targetUserId"
					type="text"
					data-testid="bulk-share-recipient" />
			</label>
			<p v-if="error" class="bulk-share__error" data-testid="bulk-share-error">
				{{ error }}
			</p>
			<!-- Gated on THIS dialog's run: the store's report outlives the
			     dialog, so an ungated panel showed the previous run's table on
			     a fresh open. -->
			<BulkRunPanel v-if="ran || bulk.progress.running" @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton
				:variant="finished ? 'primary' : 'tertiary'"
				data-testid="bulk-share-close"
				@click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="!finished"
				variant="primary"
				:disabled="targetUserId === '' || bulk.progress.running"
				data-testid="bulk-share-run"
				@click="onRun">
				{{ t('keepiq', 'Share') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog } from '@nextcloud/vue'
import BulkRunPanel from '../components/BulkRunPanel.vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useShareStore } from '../store/modules/share.js'

export default {
	name: 'BulkShareDialog',
	components: {
		NcButton,
		NcDialog,
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
			targetUserId: '',
			certificate: '',
			error: null,
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
				return this.t('keepiq', 'Shared {ok} of {total} secrets', {
					ok: this.bulk.report.filter((r) => r.status === 'ok').length,
					total: this.bulk.report.length,
				})
			}
			return this.t('keepiq', 'Share {count} secrets', {
				count: this.bulk.selectionCount,
			})
		},
	},

	methods: {
		/**
		 * The per-item fan-out: decrypt the owner's copy in-browser,
		 * re-encrypt for the recipient, register idempotently.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-ownership-and-authorization-preserved
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
		 */
		async shareOne(secretId) {
			const secretStore = useSecretStore()
			const shareStore = useShareStore()

			// fetchSecret decrypts with the session CryptoKey and returns
			// the PLAINTEXT secret — plaintext stays in this browser tab.
			const plain = await secretStore.fetchSecret(secretId)
			const snapshot = {
				key: plain.key ?? '',
				login: plain.login ?? '',
				additionalFields:
					typeof plain.additionalFields === 'object'
					&& plain.additionalFields !== null
						? JSON.stringify(plain.additionalFields)
						: (plain.additionalFields ?? ''),
			}
			const blob = await shareStore.encryptForRecipient(
				snapshot,
				this.certificate,
			)

			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/shares/register-batch'),
				{
					shares: [
						{
							sourceSecretId: secretId,
							targetUserId: this.targetUserId,
							encryptedKey: blob.key ?? '',
							encryptedLogin: blob.login ?? null,
							encryptedAdditionalFields: blob.additionalFields ?? null,
						},
					],
				},
			)
			const item = response.data?.items?.[0]
			if (item?.status === 'created' || item?.status === 'exists') {
				return {
					status: 'ok',
					reason: item.status === 'exists' ? 'already shared' : undefined,
				}
			}
			return { status: 'skipped', reason: item?.status || 'not registered' }
		},

		/**
		 * Resolve the recipient certificate once, then run the fan-out.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async onRun() {
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/shares/recipient-certificate'),
					{ params: { userId: this.targetUserId } },
				)
				this.certificate = response.data?.certificate ?? ''
			} catch (e) {
				this.error =
					e?.response?.status === 404
						? this.t(
								'keepiq',
								'Recipient has no active encryption suite',
							)
						: e?.response?.data?.message || e?.message
				return
			}

			// Set only once the certificate resolved: a recipient without an
			// active suite returns above, and that is a dialog that never ran
			// — it must keep asking, with the reason on screen.
			this.ran = true
			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.shareOne(id),
				this.t('keepiq', 'Sharing secrets'),
			)
			this.$emit('done')
		},

		/**
		 * Retry only the failed subset (idempotent server writes).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
		 */
		async onRetry() {
			await this.bulk.retryFailed(
				(id) => this.shareOne(id),
				this.t('keepiq', 'Retrying share'),
			)
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.bulk-share {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}

.bulk-share__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.bulk-share__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.bulk-share__error {
	color: var(--color-error-text);
	font-size: 13px;
}
</style>
