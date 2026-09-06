<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Share-secret dialog. Creates a password-protected PUBLIC link share: the
  secret is decrypted client-side, a one-time password is generated, an
  AES-256 key is derived via Argon2id, and only the encrypted snapshot blob is
  POSTed (the link password never reaches the server). The generated link URL
  and password are revealed exactly once. Lists + revokes existing shares.

  User-to-user sharing is shown as a DISABLED "coming soon" affordance — the
  implement-user-sharing backend is unbuilt, so there is nothing to drive yet.
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Share secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="share-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!-- One-time reveal of the freshly created link. -->
			<div v-if="createdUrl" class="share-dialog__reveal">
				<NcNoteCard type="success">
					{{
						t(
							'keepiq',
							'Link created. Copy the link and password now — the password is shown only once.',
						)
					}}
				</NcNoteCard>
				<!-- The one-time credentials as a highlighted panel, so they read
				     as THE thing to take away from this dialog. -->
				<div class="share-dialog__credentials">
					<div class="share-dialog__row">
						<span class="share-dialog__label">{{
							t('keepiq', 'Link')
						}}</span>
						<span class="share-dialog__value">{{ createdUrl }}</span>
						<CopyButton
							:value="createdUrl"
							:label="t('keepiq', 'Copy link')" />
					</div>
					<div class="share-dialog__row">
						<span class="share-dialog__label">{{
							t('keepiq', 'Password')
						}}</span>
						<span
							class="share-dialog__value share-dialog__value--mono"
							>{{ createdPassword }}</span
						>
						<CopyButton
							:value="createdPassword"
							:label="t('keepiq', 'Copy password')" />
					</div>
				</div>
			</div>

			<!-- Create form. -->
			<div v-else class="share-dialog__create">
				<p class="share-dialog__intro">
					{{
						t(
							'keepiq',
							'Create a password-protected link. Anyone with the link and password can view the secret until the usage limit is reached.',
						)
					}}
				</p>
				<NcSelect
					v-model="usageLimit"
					:options="usageOptions"
					:reduce="(opt) => opt.value"
					:inputLabel="t('keepiq', 'Usage limit')"
					:clearable="false" />
			</div>

			<!-- Existing link shares. -->
			<div class="share-dialog__existing">
				<h4>{{ t('keepiq', 'Active link shares') }}</h4>
				<NcLoadingIcon v-if="loadingShares" :size="24" />
				<p v-else-if="linkShares.length === 0" class="share-dialog__empty">
					{{ t('keepiq', 'No active link shares.') }}
				</p>
				<div
					v-for="share in linkShares"
					:key="share.id"
					class="share-dialog__share-row">
					<span class="share-dialog__value">
						{{
							t('keepiq', 'Used {used} of {limit}', {
								used: share.usageCount || 0,
								limit: share.usageLimit,
							})
						}}
					</span>
					<NcButton
						variant="tertiary"
						:aria-label="t('keepiq', 'Revoke')"
						@click="revoke(share.id)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<!-- Deferred: user-to-user share. -->
			<div class="share-dialog__user">
				<NcButton variant="secondary" :disabled="true">
					<template #icon>
						<AccountPlus :size="20" />
					</template>
					{{ t('keepiq', 'Share with a Nextcloud user (coming soon)') }}
				</NcButton>
			</div>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ createdUrl ? t('keepiq', 'Done') : t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!createdUrl"
				variant="primary"
				:disabled="creating"
				@click="createLink">
				<template #icon>
					<NcLoadingIcon v-if="creating" :size="20" />
					<ShareVariant v-else :size="20" />
				</template>
				{{ t('keepiq', 'Create link') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import CopyButton from '../components/CopyButton.vue'
import { useLinkShareStore } from '../store/modules/linkShare.js'
import { useSecretStore } from '../store/modules/secret.js'

/**
 * Create + manage password-protected public link shares for a secret. Emits
 * `close` on dismiss. The link password is generated and AES-encrypted in the
 * browser and is never transmitted to the server.
 */
export default {
	name: 'SecretShareDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		ShareVariant,
		AccountPlus,
		Delete,
		CopyButton,
	},

	props: {
		/** The ID of the secret to share. */
		secretId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			open: true,
			usageLimit: 1,
			creating: false,
			loadingShares: true,
			error: '',
			createdUrl: null,
			createdPassword: null,
		}
	},

	computed: {
		linkShares() {
			return useLinkShareStore().linkShares
		},

		usageOptions() {
			return Array.from({ length: 10 }, (_, i) => ({
				value: i + 1,
				label: String(i + 1),
			}))
		},
	},

	async mounted() {
		await this.loadShares()
	},

	beforeUnmount() {
		useLinkShareStore().clearCreatedPassword()
	},

	methods: {
		t,

		/**
		 * Load the existing link shares for this secret.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/link-sharing/spec.md#requirement-multiple-concurrent-link-shares
		 */
		async loadShares() {
			this.loadingShares = true
			try {
				await useLinkShareStore().fetchLinkShares(this.secretId)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to load link shares')
			} finally {
				this.loadingShares = false
			}
		},

		/**
		 * Forward the open-state change; clear the transient password and emit
		 * `close` when dismissed.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				useLinkShareStore().clearCreatedPassword()
				this.$emit('close')
			}
		},

		/**
		 * Decrypt the secret, build a snapshot, and create the link share. The
		 * store generates the password, derives the Argon2id AES key, encrypts
		 * the snapshot, and POSTs only the blob.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-share-a-secret-via-a-public-link
		 * @spec openspec/specs/link-sharing/spec.md#requirement-create-link-share
		 */
		async createLink() {
			this.creating = true
			this.error = ''
			try {
				const secret = await useSecretStore().fetchSecret(this.secretId)
				const snapshot = {
					name: secret.name,
					url: secret.url || null,
					login: secret.login || null,
					key: secret.key || '',
					additionalFields: secret.additionalFields || null,
				}
				const linkStore = useLinkShareStore()
				await linkStore.createLinkShare(
					this.secretId,
					snapshot,
					this.usageLimit,
				)
				this.createdUrl = linkStore.createdLinkUrl
				this.createdPassword = linkStore.createdPassword
				this.$emit('saved')
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to create link share')
			} finally {
				this.creating = false
			}
		},

		/**
		 * Revoke (delete) a link share.
		 *
		 * @param {string} id The link share ID.
		 * @return {Promise<void>}
		 * @spec openspec/specs/link-sharing/spec.md#requirement-manual-revocation
		 */
		async revoke(id) {
			this.error = ''
			try {
				await useLinkShareStore().deleteLinkShare(id)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to revoke link share')
			}
		},
	},
}
</script>

<style scoped>
.share-dialog {
	display: flex;
	flex-direction: column;
	gap: 24px;
	padding: 8px 12px 16px;
}

.share-dialog__reveal {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

/* The one-time link + password as a highlighted panel: these two values
   are the whole point of the dialog once created, and they cannot be
   retrieved again. */
.share-dialog__credentials {
	padding: 8px 16px;
	background: var(--color-background-hover, #f5f5f5);
	border-radius: var(--border-radius-large, 12px);
}

.share-dialog__row,
.share-dialog__share-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 0;
}

.share-dialog__row + .share-dialog__row,
.share-dialog__share-row + .share-dialog__share-row {
	border-top: 1px solid var(--color-border);
}

.share-dialog__label {
	width: 100px;
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.share-dialog__value {
	flex: 1 1 auto;
	min-width: 0;
	overflow-wrap: anywhere;
}

.share-dialog__value--mono {
	font-family: var(--font-face-mono, monospace);
	font-size: 14px;
}

.share-dialog__intro {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
}

.share-dialog__existing h4 {
	margin: 0 0 4px 0;
	font-size: 15px;
	font-weight: 600;
}

.share-dialog__share-row {
	padding: 6px 0;
}

.share-dialog__empty {
	margin: 4px 0;
	color: var(--color-text-maxcontrast);
}

/* Deferred affordance, visually separated from the working controls. */
.share-dialog__user {
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
