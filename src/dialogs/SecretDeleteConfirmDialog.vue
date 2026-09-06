<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Delete confirmation for a SINGLE secret. The detail sidebar's `…` menu used
  to call the store straight from the click handler, so the only irreversible
  action in the app was also the only one with no confirmation — and it is the
  path people use, because list-view rows carry no `…` menu of their own.

  There is no trash: BulkDeleteDialog says so for a selection, this says so for
  one secret. Owns the delete call and emits `deleted`, mirroring
  FolderDeleteConfirmDialog's contract, and accepts the `onDeleted` callback
  prop the sidebar's other registry modals already use.
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Delete secret')"
		:open="open"
		size="small"
		data-testid="secret-delete-dialog"
		@update:open="onUpdateOpen">
		<div class="secret-delete">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard type="warning" data-testid="secret-delete-warning">
				{{
					t(
						'keepiq',
						'This permanently deletes this secret and revokes its shares. There is no trash — this cannot be undone.',
					)
				}}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton
				variant="tertiary"
				:disabled="busy"
				data-testid="secret-delete-cancel"
				@click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				data-testid="secret-delete-confirm"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<TrashCanOutline v-else :size="20" />
				</template>
				{{ t('keepiq', 'Delete secret') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { useSecretStore } from '../store/modules/secret.js'

/**
 * Confirm and perform the deletion of a single secret. Emits `deleted` with
 * the secret id on success and `close` on dismiss.
 */
export default {
	name: 'SecretDeleteConfirmDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		TrashCanOutline,
	},

	props: {
		/** The ID of the secret to delete. */
		secretId: {
			type: String,
			required: true,
		},

		/** Optional callback fired with the secret id after a successful delete. */
		onDeleted: {
			type: Function,
			default: null,
		},
	},

	emits: ['deleted', 'close'],

	data() {
		return {
			open: true,
			busy: false,
			error: '',
		}
	},

	methods: {
		t,

		/**
		 * Forward the open-state change; emit `close` when dismissed. Dismissal
		 * is ignored while the delete request is in flight (Cancel is disabled,
		 * but Esc and the dialog's own close button arrive here too): the
		 * request would still land, so closing would read as a cancel that
		 * never happened.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec exclude Dialog open-state plumbing; no domain behaviour.
		 */
		onUpdateOpen(value) {
			if (!value && this.busy) {
				return
			}
			this.open = value
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Delete the secret and report the outcome to the host. A refused
		 * delete (403 on a delegated secret, server error, offline write)
		 * keeps the dialog open with the reason inline, so the sidebar behind
		 * it is never closed on a delete that did not happen.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-delete-secret
		 */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				await useSecretStore().deleteSecret(this.secretId)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('keepiq', 'Failed to delete secret')
				return
			} finally {
				this.busy = false
			}
			this.$emit('deleted', this.secretId)
			if (this.onDeleted) {
				this.onDeleted(this.secretId)
			}
			this.onUpdateOpen(false)
		},
	},
}
</script>

<style scoped>
.secret-delete {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>
