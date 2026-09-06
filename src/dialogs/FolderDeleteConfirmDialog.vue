<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Delete confirmation for an EMPTY vault/folder (restyle Stage 9). A folder
  with content never reaches this dialog — SubfolderResolutionDialog owns
  that protocol, because there the user must decide per subfolder what
  happens. Here there is nothing to decide, so a plain confirm is the whole
  interaction.

  Owns the delete call itself and emits `deleted`, mirroring
  SubfolderResolutionDialog's contract so the rail can host both the same
  way.
-->
<template>
	<NcDialog
		:name="
			folder.parentId
				? t('keepiq', 'Delete folder')
				: t('keepiq', 'Delete vault')
		"
		:open="open"
		size="small"
		@update:open="onUpdateOpen">
		<div class="folder-delete">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<p>
				{{
					folder.parentId
						? t(
								'keepiq',
								'This folder is empty and will be removed permanently.',
							)
						: t(
								'keepiq',
								'This vault is empty and will be removed permanently.',
							)
				}}
			</p>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				data-testid="nav-folder-delete-confirm"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<TrashCanOutline v-else :size="20" />
				</template>
				{{
					folder.parentId
						? t('keepiq', 'Delete folder')
						: t('keepiq', 'Delete vault')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { useFolderStore } from '../store/modules/folder.js'

/**
 * Confirm and perform the deletion of an empty folder/vault. Emits
 * `deleted` with the folder id on success and `close` on dismiss.
 */
export default {
	name: 'FolderDeleteConfirmDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		TrashCanOutline,
	},

	props: {
		/** The empty folder being deleted ({id, name, parentId}). */
		folder: {
			type: Object,
			required: true,
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
		 * Forward the open-state change; emit `close` when dismissed.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec exclude Dialog open-state plumbing; no domain behaviour.
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Delete the folder and report the outcome to the host.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				await useFolderStore().deleteFolder(this.folder.id)
				this.$emit('deleted', this.folder.id)
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('keepiq', 'Failed to delete folder')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.folder-delete p {
	margin: 0;
}
</style>
