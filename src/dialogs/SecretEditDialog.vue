<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Edit-secret dialog. Loads + decrypts the secret, lets the user change name /
  type / URL / login / value, and PUTs only the changed fields via the store.
  Sensitive fields (value / login) are re-encrypted client-side; metadata-only
  edits skip encryption (zero-knowledge, ADR-003).
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Edit secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<NcLoadingIcon v-if="loading" :size="32" class="secret-form__loading" />

		<div v-else class="secret-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="name"
				:label="t('keepiq', 'Name')"
				:required="true" />

			<!--
				Type is READ-ONLY once a secret exists, and deliberately so.
				The payload shape is type-dependent — the card and identity
				composites below render conditionally on `isCard` / `isIdentity`
				and serialize into `key` differently — so switching a Login to a
				Card left the card fields empty and the login fields
				unreachable, while the diff happily sent the new `typeId`.
				Bitwarden and 1Password both make type immutable after creation
				for the same reason. It stays a select in the CREATE dialog,
				where there is no existing payload to invalidate.
			-->
			<div class="secret-form__readonly">
				<span class="secret-form__readonly-label">
					{{ t('keepiq', 'Type') }}
				</span>
				<span
					class="secret-form__readonly-value"
					data-testid="secret-edit-type">
					{{ typeLabel }}
				</span>
			</div>

			<!-- Card / identity composite payloads (card-identity-items §3.1). -->
			<template v-if="isCard">
				<NcPasswordField
					v-model="card.number"
					:label="t('keepiq', 'Card number')"
					data-testid="card-number" />
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
				:disabled="saving || loading"
				@update:members="additionalFields = $event" />

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
					<ContentSave v-else :size="20" />
				</template>
				{{ t('keepiq', 'Save') }}
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
	NcTextField,
} from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Dice5 from 'vue-material-design-icons/Dice5.vue'
import AdditionalFieldsEditor from '../components/AdditionalFieldsEditor.vue'
import KeyGeneratorModal from './KeyGeneratorModal.vue'
import {
	CARD_FIELDS,
	CARD_TYPE_NAME,
	IDENTITY_FIELDS,
	IDENTITY_TYPE_NAME,
	parsePayload,
	serializeCard,
	serializeIdentity,
} from '../cardIdentity/cardIdentity.js'
import { evaluateHibp, evaluateScore, fetchPolicy } from '../policy/policy.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { membersToObject, objectToMembers } from '../utils/additionalFields.js'
import { secretTypeLabel } from '../utils/secretTypes.js'

/**
 * Edit a secret. Loads + decrypts on mount; on save sends only changed fields,
 * re-encrypting sensitive ones via the store. Emits `saved` on success and
 * `close` on dismiss.
 */
export default {
	name: 'SecretEditDialog',

	components: {
		AdditionalFieldsEditor,
		ContentSave,
		Dice5,
		KeyGeneratorModal,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
	},

	props: {
		/** The ID of the secret to edit. */
		secretId: {
			type: String,
			required: true,
		},

		/** Optional callback fired with the updated secret after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	emits: ['saved', 'close'],

	data() {
		return {
			open: true,
			loading: true,
			saving: false,
			error: '',
			original: null,
			name: '',
			typeId: null,
			value: '',
			url: '',
			login: '',
			additionalFields: [],
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
		/**
		 * This secret's type as translated display text. Replaces the type
		 * SELECT this dialog used to render — see the template comment on why
		 * type is immutable after creation.
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 */
		typeLabel() {
			return secretTypeLabel(useSecretTypeStore().typesById[this.typeId])
		},

		/**
		 * The label for the secret-value field, which reads "Note" for the
		 * `note` system type and "Secret value" otherwise.
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
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
		 * Org-policy score gate on a CHANGED manual value (§4.2) — an
		 * unchanged value is never re-gated.
		 *
		 * @return {{compliant: boolean, reason: string|null}}
		 */
		policyVerdict() {
			if (
				this.isCard
				|| this.isIdentity
				|| this.value === (this.original?.key || '')
			) {
				return { compliant: true, reason: null }
			}
			return evaluateScore(this.policy, this.selectedTypeName, this.value)
		},

		canSubmit() {
			return (
				!this.loading
				&& !this.saving
				&& this.name.trim() !== ''
				&& this.policyVerdict.compliant
			)
		},
	},

	async mounted() {
		this.policy = await fetchPolicy()
		const typeStore = useSecretTypeStore()
		if (typeStore.types.length === 0) {
			await typeStore.fetchTypes()
		}
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load + decrypt the secret and seed the form fields.
		 *
		 * Seeding the additional fields from the CURRENT decrypted copy is what bounds
		 * the last-writer-wins window: a save rewrites the whole blob, so starting
		 * from a stale copy would drop members another session added.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const secret = await useSecretStore().fetchSecret(this.secretId)
				this.original = secret
				this.name = secret.name || ''
				this.typeId = secret.typeId || null
				this.value = secret.key || ''
				this.url = secret.url || ''
				this.login = secret.login || ''
				// From the DECRYPTED blob the store already parsed. Pre-filling from
				// the current decrypted copy is also what bounds the known
				// last-writer-wins window: the whole blob is rewritten on save, so an
				// edit begun from a stale copy would drop members another session
				// added meanwhile.
				this.additionalFields = objectToMembers(secret.additionalFields)

				// Seed the per-type composite fields from the decrypted
				// payload (card-identity-items §3.1); a legacy plain value
				// falls back to the generic field untouched.
				const payload = parsePayload(secret.key || '')
				if (payload !== null && this.isCard) {
					for (const field of CARD_FIELDS) {
						this.card[field] =
							payload[field] != null ? String(payload[field]) : ''
					}
				}
				if (payload !== null && this.isIdentity) {
					for (const field of IDENTITY_FIELDS) {
						this.identity[field] =
							payload[field] != null ? String(payload[field]) : ''
					}
				}
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to load secret')
			} finally {
				this.loading = false
			}
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
		 * Compute the changed-fields diff and PUT it via the store.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				// HIBP block on a changed manual value (§4.2) — before encryption.
				if (
					!this.isCard
					&& !this.isIdentity
					&& this.value !== (this.original?.key || '')
				) {
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
				const diff = {}
				const o = this.original
				if (this.name.trim() !== (o.name || '')) {
					diff.name = this.name.trim()
				}
				// No `typeId` branch: type is read-only in this dialog (see the
				// template), so it cannot differ from the original — and if a
				// future change reintroduces a way to alter it, this must not
				// be the code that quietly ships a type switch to the server
				// with a payload built for the old shape.
				if ((this.url || '') !== (o.url || '')) {
					diff.url = this.url || null
				}
				if ((this.effectiveValue || '') !== (o.key || '')) {
					diff.key = this.effectiveValue
				}
				if ((this.login || '') !== (o.login || '')) {
					diff.login = this.login
				}

				// One blob is the storage unit, so ANY member change rewrites all of
				// it. Sent only when something actually changed, and sent as `{}`
				// rather than null when the last member is removed: null would mean
				// "not provided", which the store reads as "leave the stored blob
				// alone" — the opposite of what removing the last field means.
				const nextMembers = membersToObject(this.additionalFields)
				const priorMembers = membersToObject(
					objectToMembers(o.additionalFields),
				)
				if (JSON.stringify(nextMembers) !== JSON.stringify(priorMembers)) {
					diff.additionalFields = nextMembers
				}

				let updated = this.original
				if (Object.keys(diff).length > 0) {
					updated = await useSecretStore().updateSecret(
						this.secretId,
						diff,
					)
				}
				this.$emit('saved', updated)
				if (this.onSaved) {
					this.onSaved(updated)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to save secret')
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

.secret-form__readonly {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

/* Mirrors NcTextField’s label metrics so the read-only Type row lines up
   with the editable fields above and below it. */
.secret-form__readonly-label {
	font-size: var(--default-font-size, 15px);
	color: var(--color-text-maxcontrast);
}

.secret-form__readonly-value {
	padding: 4px 0;
	font-weight: 500;
}

.secret-form__loading {
	display: flex;
	justify-content: center;
	margin: 24px 0;
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
