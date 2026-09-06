<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create-folder dialog. Folders hold no encrypted material, so this is a plain
  name (+ optional parent) form wired to the folder store.
-->
<template>
	<!-- Level-appropriate wording (restyle terminology): a TOP-LEVEL folder
	     is a Vault, one inside a vault is a folder. The LEVEL IS FIXED by
	     the opening context (team decision): a vault is only ever created
	     at the root — no parent picker at all — and a folder only ever
	     inside a vault, so the picker never offers the root. The two flows
	     can no longer morph into each other mid-dialog. -->
	<NcDialog
		:name="isVaultLevel ? t('keepiq', 'New vault') : t('keepiq', 'New folder')"
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

			<NcSelect
				v-if="!isVaultLevel"
				v-model="selectedParentId"
				:options="parentOptions"
				:reduce="(opt) => opt.value"
				:inputLabel="t('keepiq', 'Parent folder')"
				:clearable="false" />

			<!-- Vault personalization (restyle Stage 9, Proton pattern):
			     only TOP-LEVEL folders are Vaults and carry an icon + color;
			     nested folders keep the plain glyph, so the picker follows
			     the selected parent like the wording does. Unset = the Safe
			     default. -->
			<CnIconColorPicker
				v-if="isVaultLevel"
				v-model:icon="customIcon"
				v-model:color="customColor"
				:fallbackIcon="safeIcon"
				:translate="translateLabel"
				data-testid="folder-create-style-picker" />
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSubmit" @click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<Safe v-else-if="isVaultLevel" :size="20" />
					<FolderPlus v-else :size="20" />
				</template>
				{{
					isVaultLevel
						? t('keepiq', 'Create vault')
						: t('keepiq', 'Create folder')
				}}
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
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { markRaw } from 'vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import Safe from 'vue-material-design-icons/Safe.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { folderPathLabel } from '../utils/vaultList.js'

/**
 * Create a folder via the folder store. Emits `saved` with the new folder on
 * success and `close` on dismiss.
 */
export default {
	name: 'FolderCreateDialog',

	components: {
		CnIconColorPicker,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		FolderPlus,
		Safe,
	},

	props: {
		/** The parent folder to create under (defaults to root / current view). */
		parentId: {
			type: String,
			default: null,
		},

		/** Optional callback fired with the created folder after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	emits: ['saved', 'close'],

	data() {
		return {
			open: true,
			name: '',
			selectedParentId: this.parentId,
			saving: false,
			error: '',
			/** Picked customization keys (vault level only; null = default). */
			customIcon: null,
			customColor: null,
			/** The vaults' default glyph, for the picker's Default cell. */
			safeIcon: markRaw(Safe),
		}
	},

	computed: {
		/**
		 * Whether the dialog creates a VAULT: fixed by the OPENING context
		 * (no parentId = opened at the root), never by the picker. A vault
		 * is only ever created at the root (team decision), so the vault
		 * flow shows no parent picker at all.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		isVaultLevel() {
			return !this.parentId
		},

		/**
		 * The parent-folder picker options (FOLDER creation only): every
		 * folder the user owns — WITHOUT the root, because creating at the
		 * root is the vault flow and the two never morph into each other —
		 * path-labelled ("A / B / C") so same-named nested folders stay
		 * distinguishable (restyle Stage 6).
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		parentOptions() {
			const folders = useFolderStore().folders
			return folders.map((folder) => ({
				value: folder.id,
				label: folderPathLabel(folders, folder.id) || folder.name,
			}))
		},

		canSubmit() {
			return !this.saving && this.name.trim() !== ''
		},
	},

	async mounted() {
		const folderStore = useFolderStore()
		if (folderStore.folders.length === 0) {
			await folderStore.fetchFolders()
		}
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
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Create the folder via the store.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const created = await useFolderStore().createFolder(
					this.name.trim(),
					this.selectedParentId,
					// Customization only exists at vault level — a picker
					// choice made before switching to a nested parent must
					// not ride along.
					this.isVaultLevel
						? {
								customIcon: this.customIcon,
								customColor: this.customColor,
							}
						: {},
				)
				this.$emit('saved', created)
				if (this.onSaved) {
					this.onSaved(created)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to create folder')
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
