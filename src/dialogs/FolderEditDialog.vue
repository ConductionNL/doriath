<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Edit-folder dialog (restyle Stage 9): rename plus — at VAULT level — the
  Proton-style icon & color picker. This dialog REINTRODUCES rename: the
  store's updateFolder had no UI caller since the old FolderTree left, so
  this is the rename surface, not just the customize surface.

  Save always sends the two customization keys for a vault (explicit null
  CLEARS server-side, so picking a Default cell genuinely resets); the
  name rides along only when it changed, and the backend applies absent
  keys as untouched.
-->
<template>
	<NcDialog
		:name="isVaultLevel ? t('keepiq', 'Edit vault') : t('keepiq', 'Edit folder')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="folder-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="name"
				:label="
					isVaultLevel
						? t('keepiq', 'Vault name')
						: t('keepiq', 'Folder name')
				"
				:required="true" />

			<!-- Personalization applies to VAULTS (top-level folders) only —
			     nested folders keep the plain glyph (Stage 9 scope). -->
			<CnIconColorPicker
				v-if="isVaultLevel"
				v-model:icon="customIcon"
				v-model:color="customColor"
				:fallbackIcon="safeIcon"
				:translate="translateLabel"
				data-testid="folder-edit-style-picker" />
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit"
				data-testid="folder-edit-save"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				{{ t('keepiq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnIconColorPicker } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { markRaw } from 'vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Safe from 'vue-material-design-icons/Safe.vue'
import { useFolderStore } from '../store/modules/folder.js'

/**
 * Edit a folder via the folder store: rename, and (vault-level) icon +
 * color. Emits `saved` with the updated folder on success and `close` on
 * dismiss.
 */
export default {
	name: 'FolderEditDialog',

	components: {
		CnIconColorPicker,
		ContentSaveOutline,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** The folder being edited ({id, name, parentId, customIcon, customColor}). */
		folder: {
			type: Object,
			required: true,
		},
	},

	emits: ['saved', 'close'],

	data() {
		return {
			open: true,
			name: this.folder.name || '',
			customIcon: this.folder.customIcon ?? null,
			customColor: this.folder.customColor ?? null,
			saving: false,
			error: '',
			/** The vaults' default glyph, for the picker's Default cell. */
			safeIcon: markRaw(Safe),
		}
	},

	computed: {
		/**
		 * Whether the edited folder is a top-level Vault — customization
		 * (and the dialog's wording) follows the Stage-5 terminology split.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		isVaultLevel() {
			return !this.folder.parentId
		},

		/**
		 * Whether Save may run: not already saving, and a name is present.
		 *
		 * @return {boolean}
		 * @spec exclude Form-enablement guard; no domain behaviour.
		 */
		canSubmit() {
			return !this.saving && this.name.trim() !== ''
		},
	},

	methods: {
		t,

		/**
		 * Translate a picker label through keepiq's catalog (the picker's
		 * labels are library-side English source strings).
		 *
		 * @param {string} label The English source label.
		 * @return {string} The translated label.
		 * @spec exclude Pure i18n pass-through.
		 */
		translateLabel(label) {
			return t('keepiq', label)
		},

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
		 * Save the changes via the store. The name rides along only when it
		 * changed (the backend renames on any present name); for a vault the
		 * two customization keys are ALWAYS sent so an explicit null clears
		 * a stored value (key-present-with-null semantics).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			const data = {}
			const trimmed = this.name.trim()
			if (trimmed !== this.folder.name) {
				data.name = trimmed
			}
			if (this.isVaultLevel) {
				data.customIcon = this.customIcon
				data.customColor = this.customColor
			}
			try {
				const updated = await useFolderStore().updateFolder(
					this.folder.id,
					data,
				)
				this.$emit('saved', updated)
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to update folder')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.folder-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}
</style>
