<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Developer-facing application registration form. Lets a developer or
  admin register a new application by name + description + type, with
  an optional CSR upload.

  Rendered as a real NcDialog overlay: the first cut was a bare
  <section role="dialog"> in document flow, which painted below the
  fold and made "Register application" look like a dead button.

  The type choice is a two-option radio group on purpose — an NcSelect
  inside an NcDialog either teleports behind the modal (dead control)
  or is clipped by the dialog's content box (invisible control), see
  the history in src/dialogs/MoveDialog.vue.

  When the server returns a one-time private key (because no CSR was
  supplied) the parent view should mount PrivateKeyDownloadDialog to
  surface it; that flow is owned by the parent so the key can be
  copied or downloaded before this dialog closes.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.3
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Register application')"
		:open="open"
		size="normal"
		data-testid="application-register-dialog"
		@update:open="onUpdateOpen">
		<form class="app-register-form" @submit.prevent="onSubmit">
			<NcNoteCard
				v-if="error"
				type="error"
				data-testid="application-register-error">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="form.name"
				:label="t('keepiq', 'Name')"
				:required="true"
				maxlength="120"
				data-testid="application-register-name" />

			<NcTextArea
				v-model="form.description"
				:label="t('keepiq', 'Description')"
				rows="2"
				maxlength="500"
				data-testid="application-register-description" />

			<fieldset
				class="app-register-form__type"
				data-testid="application-register-type">
				<legend>{{ t('keepiq', 'Type') }}</legend>
				<NcCheckboxRadioSwitch
					:modelValue="form.type"
					value="internal"
					name="application-type"
					type="radio"
					data-testid="application-register-type-internal"
					@update:modelValue="form.type = $event">
					{{ t('keepiq', 'Internal (Nextcloud app)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:modelValue="form.type"
					value="external"
					name="application-type"
					type="radio"
					data-testid="application-register-type-external"
					@update:modelValue="form.type = $event">
					{{ t('keepiq', 'External (offsite service)') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<div class="app-register-form__csr">
				<NcTextArea
					v-model="form.csr"
					:label="t('keepiq', 'CSR (optional, PEM-encoded)')"
					rows="6"
					placeholder="-----BEGIN CERTIFICATE REQUEST-----..."
					data-testid="application-register-csr" />
				<input
					type="file"
					accept=".csr,.pem,text/plain"
					:aria-label="t('keepiq', 'CSR (optional, PEM-encoded)')"
					data-testid="application-register-csr-upload"
					@change="onCsrUpload" />
			</div>
		</form>

		<template #actions>
			<NcButton
				variant="tertiary"
				data-testid="application-register-cancel"
				@click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit"
				data-testid="application-register-submit"
				@click="onSubmit">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<Plus v-else :size="20" />
				</template>
				{{ busy ? t('keepiq', 'Submitting…') : t('keepiq', 'Register') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useApplicationStore } from '../store/modules/application.js'

export default {
	name: 'ApplicationRegisterDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
		Plus,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'registered'],
	data() {
		return {
			form: {
				name: '',
				description: '',
				type: 'internal',
				csr: '',
			},

			busy: false,
			error: null,
		}
	},

	computed: {
		/**
		 * Whether Register may run: not already submitting and a name given.
		 *
		 * @return {boolean}
		 * @spec exclude Form-enablement guard; no domain behaviour.
		 */
		canSubmit() {
			return !this.busy && this.form.name.trim() !== ''
		},
	},

	watch: {
		/**
		 * Reset the form when the parent closes the dialog.
		 *
		 * @param {boolean} val The new open state.
		 * @spec exclude Dialog open-state reset plumbing; no domain behaviour.
		 */
		open(val) {
			if (val === false) {
				this.form = { name: '', description: '', type: 'internal', csr: '' }
				this.busy = false
				this.error = null
			}
		},
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
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Submit the registration, optionally carrying a PKCS#10 CSR.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-register-application
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-encryptionsuite-via-csr
		 */
		async onSubmit() {
			// Re-entrancy gate: the form's @submit and the actions-slot
			// button share this handler; should a refactor ever let both
			// fire for one gesture, the second entry finds the gate shut
			// before any request is built.
			if (this.busy) {
				return
			}
			this.error = null
			const name = this.form.name.trim()
			if (name === '') {
				this.error = t('keepiq', 'Name is required')
				return
			}
			// Trimmed like name: a pasted trailing newline is a spurious 400
			// on strict servers or invisible garbage in storage.
			const description = this.form.description.trim()
			const csr = this.form.csr.trim()
			const store = useApplicationStore()
			this.busy = true
			try {
				const row = await store.registerApplication({
					name,
					description,
					type: this.form.type,
					csr: csr || null,
				})
				this.$emit('registered', row)
				// Self-sufficient close: a cnOpenModal host that only wires
				// @registered must not be left with a stuck dialog. The
				// register view also closes on @registered — setting its
				// flag twice is harmless.
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to register')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Read an uploaded CSR file into the PEM textarea.
		 *
		 * @param {Event} event The file input's change event.
		 * @return {void}
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-encryptionsuite-via-csr
		 */
		onCsrUpload(event) {
			const file = event.target.files && event.target.files[0]
			if (!file) {
				return
			}
			const reader = new FileReader()
			reader.onload = () => {
				this.form.csr = String(reader.result || '')
			}
			reader.readAsText(file)
		},
	},
}
</script>

<style scoped>
.app-register-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.app-register-form__type {
	border: 0;
	padding: 0;
	margin: 0;
}

.app-register-form__type legend {
	font-weight: 600;
	margin-bottom: 4px;
}

.app-register-form__csr {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
