<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create-secret dialog. Collects name + type + value (+ optional URL / login /
  folder) and creates the secret via the secret store, which RSA-encrypts the
  sensitive fields client-side before the POST (zero-knowledge, ADR-003).
-->
<template>
	<NcDialog
		:name="t('keepiq', 'New secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="secret-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="locked" type="warning">
				{{ t('keepiq', 'Unlock the vault before creating a secret.') }}
			</NcNoteCard>

			<NcTextField
				v-model="name"
				:label="t('keepiq', 'Name')"
				:required="true" />

			<NcSelect
				v-model="typeId"
				:options="typeOptions"
				:inputLabel="t('keepiq', 'Type')"
				:reduce="(opt) => opt.value"
				:clearable="false" />

			<!-- Card / identity composite payloads (card-identity-items §3.1):
			     per-type field sets serialized to the encrypted key on save. -->
			<template v-if="isCard">
				<NcPasswordField
					v-model="card.number"
					:label="t('keepiq', 'Card number')"
					data-testid="card-number" />
				<NcNoteCard
					v-if="card.number !== '' && !luhnOk"
					type="warning"
					data-testid="card-luhn-hint">
					{{
						t(
							'keepiq',
							'This number does not pass the card checksum — double-check it (saving is not blocked).',
						)
					}}
				</NcNoteCard>
				<NcTextField
					v-model="card.expiry"
					:label="t('keepiq', 'Expiry (MM/YY)')"
					data-testid="card-expiry" />
				<NcPasswordField
					v-model="card.cvv"
					:label="t('keepiq', 'CVV')"
					data-testid="card-cvv" />
				<NcPasswordField
					v-model="card.pin"
					:label="t('keepiq', 'PIN (optional)')"
					data-testid="card-pin" />
				<NcTextField
					v-model="card.cardholder"
					:label="t('keepiq', 'Cardholder name')"
					data-testid="card-cardholder" />
			</template>
			<template v-else-if="isIdentity">
				<NcTextField
					v-model="identity.firstName"
					:label="t('keepiq', 'First name')"
					data-testid="identity-first-name" />
				<NcTextField
					v-model="identity.lastName"
					:label="t('keepiq', 'Last name')"
					data-testid="identity-last-name" />
				<NcTextField
					v-model="identity.address"
					:label="t('keepiq', 'Address')"
					data-testid="identity-address" />
				<NcTextField
					v-model="identity.phone"
					:label="t('keepiq', 'Phone')"
					data-testid="identity-phone" />
				<NcTextField
					v-model="identity.email"
					:label="t('keepiq', 'Email')"
					data-testid="identity-email" />
				<NcPasswordField
					v-model="identity.bsn"
					:label="t('keepiq', 'BSN')"
					data-testid="identity-bsn" />
			</template>
			<div v-else class="secret-form__value-row">
				<NcPasswordField
					v-model="value"
					class="secret-form__value-field"
					:label="valueLabel" />
				<NcButton
					variant="tertiary-no-background"
					:title="t('keepiq', 'Generate a strong key')"
					:aria-label="t('keepiq', 'Generate a strong key')"
					@click="openGenerator">
					<template #icon>
						<Dice5 :size="20" />
					</template>
				</NcButton>
			</div>

			<KeyGeneratorModal
				v-if="generatorOpen"
				:open="generatorOpen"
				@update:open="generatorOpen = $event"
				@generated="onGenerated" />

			<NcTextField v-model="url" :label="t('keepiq', 'URL (optional)')" />

			<NcTextField v-model="login" :label="t('keepiq', 'Login (optional)')" />

			<AdditionalFieldsEditor
				:members="additionalFields"
				:disabled="saving"
				@update:members="additionalFields = $event" />

			<!-- The shared destination picker (no "Vault root" option, by
			     design): a secret always lives in a vault, so creating one
			     at the root cannot be offered. Replaces a local NcSelect
			     that still listed the root. -->
			<DestinationSelect
				v-model="selectedFolderId"
				mode="folders"
				:label="t('keepiq', 'Folder')" />

			<NcNoteCard
				v-if="!policyVerdict.compliant"
				type="warning"
				data-testid="policy-blocked">
				{{ policyVerdict.reason }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSubmit" @click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<Plus v-else :size="20" />
				</template>
				{{ t('keepiq', 'Create secret') }}
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
	NcPasswordField,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import Dice5 from 'vue-material-design-icons/Dice5.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import AdditionalFieldsEditor from '../components/AdditionalFieldsEditor.vue'
import DestinationSelect from '../components/DestinationSelect.vue'
import KeyGeneratorModal from './KeyGeneratorModal.vue'
import {
	CARD_TYPE_NAME,
	IDENTITY_TYPE_NAME,
	luhnValid,
	serializeCard,
	serializeIdentity,
} from '../cardIdentity/cardIdentity.js'
import { evaluateHibp, evaluateScore, fetchPolicy } from '../policy/policy.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useSessionStore } from '../store/modules/session.js'
import { membersToObject } from '../utils/additionalFields.js'
import { secretTypeLabel } from '../utils/secretTypes.js'

/**
 * Create a secret. The value (and optional login) are RSA-encrypted by the
 * store using the suite certificate before the request leaves the browser.
 * Emits `saved` with the created secret on success and `close` on dismiss.
 */
export default {
	name: 'SecretCreateDialog',

	components: {
		AdditionalFieldsEditor,
		DestinationSelect,
		Dice5,
		KeyGeneratorModal,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextField,
		Plus,
	},

	props: {
		/** The folder to create the secret in (defaults to the current view). */
		folderId: {
			type: String,
			default: null,
		},

		/** Optional callback fired with the created secret after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			open: true,
			name: '',
			typeId: null,
			value: '',
			url: '',
			login: '',
			additionalFields: [],
			selectedFolderId: this.folderId,
			saving: false,
			error: '',
			generatorOpen: false,
			card: { number: '', expiry: '', cvv: '', pin: '', cardholder: '' },
			identity: {
				firstName: '',
				lastName: '',
				address: '',
				phone: '',
				email: '',
				bsn: '',
			},

			policy: null,
		}
	},

	computed: {
		locked() {
			return useSessionStore().isLocked
		},

		/**
		 * The seeded secret types as select options, labels translated.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 */
		typeOptions() {
			return useSecretTypeStore().types.map((type) => ({
				value: type.id,
				label: secretTypeLabel(type),
			}))
		},

		/**
		 * The label for the secret-value field, which reads "Note" for the
		 * `note` system type and "Secret value" otherwise.
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		valueLabel() {
			const type = useSecretTypeStore().typesById[this.typeId]
			return type && type.name === 'note'
				? t('keepiq', 'Note')
				: t('keepiq', 'Secret value')
		},

		/** The selected type's system name (card-identity-items §3.1). */
		selectedTypeName() {
			return useSecretTypeStore().typesById[this.typeId]?.name ?? ''
		},

		isCard() {
			return this.selectedTypeName === CARD_TYPE_NAME
		},

		isIdentity() {
			return this.selectedTypeName === IDENTITY_TYPE_NAME
		},

		/** Best-effort Luhn hint — never blocks saving (§3.2). */
		luhnOk() {
			return luhnValid(this.card.number)
		},

		/** The value serialized for the encrypted key field. */
		effectiveValue() {
			if (this.isCard) {
				return serializeCard(this.card)
			}
			if (this.isIdentity) {
				return serializeIdentity(this.identity)
			}
			return this.value
		},

		/**
		 * Org-policy score gate on the manual value (org-password-policies
		 * §4.2): exempt types skip; the reason renders and submit stays
		 * disabled until compliant. Never POSTs a non-compliant value.
		 *
		 * @return {{compliant: boolean, reason: string|null}}
		 */
		policyVerdict() {
			if (this.isCard || this.isIdentity) {
				return { compliant: true, reason: null }
			}
			return evaluateScore(this.policy, this.selectedTypeName, this.value)
		},

		/**
		 * Whether Create may run: not busy, named, a folder chosen (secrets
		 * cannot live at the vault root), and the type's own required value
		 * present and policy-compliant.
		 *
		 * @return {boolean}
		 * @spec exclude Form-enablement guard; no domain behaviour.
		 */
		canSubmit() {
			if (this.saving || this.locked || this.name.trim() === '') {
				return false
			}
			if (!this.selectedFolderId) {
				return false
			}
			if (this.isCard) {
				return this.card.number !== ''
			}
			if (this.isIdentity) {
				return Object.values(this.identity).some((v) => v !== '')
			}
			return this.value !== '' && this.policyVerdict.compliant
		},
	},

	async mounted() {
		this.policy = await fetchPolicy()
		const typeStore = useSecretTypeStore()
		if (typeStore.types.length === 0) {
			await typeStore.fetchTypes()
		}
		if (this.typeId === null && typeStore.types.length > 0) {
			const login = typeStore.types.find((type) => type.name === 'login')
			this.typeId = login ? login.id : typeStore.types[0].id
		}
		const folderStore = useFolderStore()
		if (folderStore.folders.length === 0) {
			await folderStore.fetchFolders()
		}
	},

	methods: {
		t,

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
		 * Open the key generator dialog.
		 *
		 * @return {void}
		 */
		openGenerator() {
			this.generatorOpen = true
		},

		/**
		 * Receive the generated key from the modal and copy it into the value field.
		 *
		 * @param {string} key The generated key.
		 * @return {void}
		 */
		onGenerated(key) {
			if (typeof key === 'string' && key.length > 0) {
				this.value = key
			}
		},

		/**
		 * Encrypt (in the store) and create the secret.
		 *
		 * Additional fields travel as a plain name/value object; the store turns them
		 * into the single encrypted blob, which is the only place that knows the blob
		 * is the storage unit.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				// HIBP block (org-password-policies §4.2): k-anonymity check
				// BEFORE encryption; only the 5-char prefix leaves the browser.
				if (!this.isCard && !this.isIdentity) {
					const hibpReason = await evaluateHibp(
						this.policy,
						this.selectedTypeName,
						this.value,
					)
					if (hibpReason !== null) {
						this.error = hibpReason
						this.saving = false
						return
					}
				}
				const created = await useSecretStore().createSecret({
					name: this.name.trim(),
					typeId: this.typeId,
					folderId: this.selectedFolderId,
					url: this.url || null,
					login: this.login || '',
					key: this.effectiveValue,
					// Only when there ARE members: the store encrypts whatever it is
					// handed, so passing {} unconditionally would write an empty
					// ciphertext blob onto every secret ever created here.
					...(this.additionalFields.length > 0
						? {
								additionalFields: membersToObject(
									this.additionalFields,
								),
							}
						: {}),
				})
				this.$emit('saved', created)
				if (this.onSaved) {
					this.onSaved(created)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to create secret')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.secret-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.secret-form__value-row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.secret-form__value-field {
	flex: 1 1 auto;
}
</style>
