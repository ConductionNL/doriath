<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The one move dialog: it moves a SECRET into a vault or folder, or moves a
  VAULT'S CONTENTS into another vault. Replaces SecretMoveDialog and
  FolderMoveDialog, which were two dialogs for one verb and had drifted in
  look, in wording, and in which fixes reached them.

  What `subject` switches, and nothing else:

    subject="secret"  one metadata-only PUT (`folderId`), atomic. Destination
                      is any vault or folder.
    subject="vault"   the vault's own secrets and its direct subfolders, moved
                      one at a time into another VAULT. Vaults never re-parent
                      (team decision), so the destination is a vault.

  The vault transfer is client-driven because there is no bulk endpoint, so it
  cannot be atomic. What it can do — and does — is carry on past a failure and
  name every item that stayed behind, because "some items may have moved"
  without a list leaves the user with two vaults and no way to tell which is
  which. That is why the two submit paths are separate methods rather than one
  interleaved routine: they share a picker and a frame, not a mechanism.
-->
<template>
	<NcDialog
		:name="title"
		:open="open"
		size="normal"
		data-testid="move-dialog"
		@update:open="onUpdateOpen">
		<div class="move-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
				<!-- Naming the items that stayed behind is the whole point:
				     without it "some items may have moved" leaves the user
				     with two vaults and no way to tell them apart. -->
				<ul v-if="failures.length" class="move-form__failures">
					<li v-for="failure in failures" :key="failure.id">
						{{ failure.name }} — {{ failure.message }}
					</li>
				</ul>
			</NcNoteCard>

			<p v-if="isVault" class="move-form__hint">
				{{
					isEmpty
						? t(
								'keepiq',
								'This vault is empty — there is nothing to move.',
							)
						: t(
								'keepiq',
								'Move everything in this vault to another vault.',
							)
				}}
			</p>

			<DestinationSelect
				v-model="target"
				:mode="isVault ? 'vaults' : 'folders'"
				:excludeId="excludeId"
				:label="destinationLabel"
				:disabled="isEmpty" />
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit"
				data-testid="move-dialog-save"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<FolderMove v-else :size="20" />
				</template>
				{{ t('keepiq', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import DestinationSelect from '../components/DestinationSelect.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'

/**
 * Move a secret, or a vault's contents. Emits `saved` on success and `close`
 * on dismiss.
 */
export default {
	name: 'MoveDialog',

	components: {
		DestinationSelect,
		FolderMove,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** What is being moved: `secret` or `vault` (its contents). */
		subject: {
			type: String,
			required: true,
			validator: (value) => ['secret', 'vault'].includes(value),
		},

		/** The secret to move (`subject="secret"`). */
		secretId: {
			type: String,
			default: null,
		},

		/** The secret's current folder, preselected (`subject="secret"`). */
		currentFolderId: {
			type: String,
			default: null,
		},

		/** The vault whose contents move, `{id, name}` (`subject="vault"`). */
		folder: {
			type: Object,
			default: null,
		},

		/** Optional callback fired with the result after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	emits: ['saved', 'close'],

	data() {
		return {
			open: true,
			/** @type {string|null} The chosen destination. */
			target: this.subject === 'secret' ? this.currentFolderId : null,
			/** @type {object|null} The source vault's children (`vault` only). */
			children: null,
			saving: false,
			/**
			 * Set when the dialog is dismissed while a transfer is running:
			 * the per-item vault loop checks it between items and stops.
			 */
			cancelled: false,
			error: '',
			/**
			 * @type {Array<{id: string, name: string, message: string}>}
			 * Items that stayed behind, named so the split state is visible.
			 */
			failures: [],
		}
	},

	computed: {
		/** @return {boolean} Whether a vault's contents are being moved. */
		isVault() {
			return this.subject === 'vault'
		},

		/**
		 * @return {string} The dialog title.
		 * @spec exclude Presentation; no domain behaviour.
		 */
		title() {
			return this.isVault
				? t('keepiq', 'Move vault contents')
				: t('keepiq', 'Move secret')
		},

		/**
		 * @return {string} The destination control's label.
		 * @spec exclude Presentation; no domain behaviour.
		 */
		destinationLabel() {
			return this.isVault
				? t('keepiq', 'Target vault')
				: t('keepiq', 'Destination folder')
		},

		/**
		 * The subtree the picker must not offer: a vault cannot receive its
		 * own contents.
		 *
		 * @return {string|null} A folder id, or null.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		excludeId() {
			return this.isVault && this.folder ? this.folder.id : null
		},

		/**
		 * Whether the source vault holds neither secrets nor subfolders — an
		 * empty vault has nothing to transfer, so the dialog says that instead
		 * of offering a no-op move. False while the payload is still loading,
		 * so the controls never flash disabled, and always false for a secret.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-folder-children
		 */
		isEmpty() {
			if (!this.isVault || this.children === null) {
				return false
			}
			return (
				(this.children.directSecretCount || 0) === 0
				&& (this.children.subfolders || []).length === 0
			)
		},

		/**
		 * Whether Move may run: not already moving, a destination chosen, and
		 * for a vault, something to move. A secret's picker preselects its
		 * CURRENT folder so "you are here" is visible, but Move stays
		 * disarmed until the destination actually changes — an unchanged
		 * target would fire a no-op PUT that keeps the secret where it is.
		 *
		 * @return {boolean}
		 * @spec exclude Form-enablement guard; no domain behaviour.
		 */
		canSubmit() {
			if (this.saving || this.isEmpty || !this.target) {
				return false
			}
			if (!this.isVault && this.target === this.currentFolderId) {
				return false
			}
			return true
		},
	},

	/**
	 * For a vault, load its children payload for the emptiness check. A secret
	 * needs no preflight.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/secrets/spec.md#requirement-list-folder-children
	 */
	async mounted() {
		if (!this.isVault) {
			return
		}
		try {
			this.children = await useFolderStore().fetchChildren(this.folder.id)
		} catch {
			// No payload — keep the move offered; the transfer itself will
			// surface any real problem.
		}
	},

	methods: {
		t,

		/**
		 * Forward a USER dismissal; emit `close`. Dismissed mid-transfer,
		 * it aborts the vault loop at the next item and puts the shared
		 * list back right away — the loop's full-vault fetch replaced it,
		 * and its own finally no longer restores it once cancelled (the
		 * user may have navigated elsewhere by the time the loop winds
		 * down). Programmatic closes after success go through closeDone().
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec exclude Dialog open-state plumbing; no domain behaviour.
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				if (this.saving) {
					this.cancelled = true
					useSecretStore()
						.fetchSecrets()
						.catch(() => {})
				}
				this.$emit('close')
			}
		},

		/**
		 * Close after a completed operation — no cancel semantics, so the
		 * vault loop's finally still restores the visible list.
		 *
		 * @return {void}
		 * @spec exclude Dialog open-state plumbing; no domain behaviour.
		 */
		closeDone() {
			this.open = false
			this.$emit('close')
		},

		/**
		 * Dispatch to the mechanism this subject needs. The two are kept apart
		 * deliberately: one atomic PUT and a non-atomic client-driven loop
		 * share nothing but the frame around them.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			return this.isVault ? this.moveVaultContents() : this.moveSecret()
		},

		/**
		 * Move one secret: a metadata-only `folderId` update, so no
		 * re-encryption is needed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		async moveSecret() {
			this.saving = true
			this.error = ''
			try {
				const updated = await useSecretStore().updateSecret(this.secretId, {
					folderId: this.target,
				})
				this.$emit('saved', updated)
				if (this.onSaved) {
					this.onSaved(updated)
				}
				this.closeDone()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to move secret')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Transfer a vault's contents: every direct secret via updateSecret
		 * (folderId only — no re-encryption), then every direct subfolder via
		 * updateFolder (each carries its subtree). Finally refresh the visible
		 * list, which the full-vault fetch replaced.
		 *
		 * There is no bulk endpoint, so this is not atomic. Each item is
		 * therefore attempted on its own: a failure is recorded and the run
		 * continues, and the dialog stays open listing exactly what is still in
		 * the source vault, so the split state is recoverable by moving again
		 * rather than merely announced.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		async moveVaultContents() {
			this.saving = true
			this.error = ''
			this.failures = []
			const folderStore = useFolderStore()
			const secretStore = useSecretStore()
			const failures = []
			try {
				// The subfolder set comes from the SERVER, not from
				// `folderStore.folders`: that list is hydrated on nav mount and
				// on vault-unlock, so a subfolder created since (another tab,
				// another device) is absent from it and the move would silently
				// leave it behind.
				const children = await folderStore.fetchChildren(this.folder.id)
				this.children = children
				const subfolders = children.subfolders || []
				const secrets = await secretStore.fetchAllSecrets({
					folderId: this.folder.id,
				})

				// Per item, so one failure does not strand the rest in the
				// source vault — and per item is also the abort granularity:
				// a dismissal mid-transfer stops before the next PUT rather
				// than churning on invisibly after the dialog is gone.
				for (const secret of secrets) {
					if (this.cancelled) {
						return
					}
					try {
						await secretStore.updateSecret(secret.id, {
							folderId: this.target,
						})
					} catch (e) {
						failures.push(this.describeFailure(secret, e))
					}
				}
				for (const subfolder of subfolders) {
					if (this.cancelled) {
						return
					}
					try {
						await folderStore.updateFolder(subfolder.id, {
							parentId: this.target,
							move: true,
						})
					} catch (e) {
						failures.push(this.describeFailure(subfolder, e))
					}
				}
				if (this.cancelled) {
					return
				}

				if (failures.length > 0) {
					this.failures = failures
					this.error = t(
						'keepiq',
						'Failed to move the vault contents — some items may have moved already.',
					)
					return
				}

				this.$emit('saved', this.folder)
				if (this.onSaved) {
					this.onSaved(this.folder)
				}
				this.closeDone()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t(
						'keepiq',
						'Failed to move the vault contents — some items may have moved already.',
					)
			} finally {
				// The full-vault fetch replaced the shared list state; restore
				// the visible list with the store's active filters. Not after
				// a cancel: onUpdateOpen already restored it at cancel time,
				// and by now the user may be looking at something else.
				if (!this.cancelled) {
					secretStore.fetchSecrets().catch(() => {})
				}
				this.saving = false
			}
		},

		/**
		 * Name one item that stayed behind, with the server's reason.
		 *
		 * @param {object} item The secret or subfolder that failed to move.
		 * @param {Error|object} error The rejection from the update call.
		 * @return {{id: string, name: string, message: string}} A failure row.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		describeFailure(item, error) {
			return {
				id: item.id,
				name: item.name || item.id,
				message:
					error?.response?.data?.message
					|| error?.message
					|| t('keepiq', 'Unknown error'),
			}
		},
	},
}
</script>

<style scoped>
.move-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.move-form__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

/* The list of items that stayed behind. Capped and scrollable so a large
   vault cannot push the dialog's own buttons off screen. */
.move-form__failures {
	margin: 8px 0 0;
	padding-inline-start: 20px;
	max-height: 140px;
	overflow-y: auto;
}
</style>
