<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Revoke-an-emergency-contact confirmation.

  Deleting an emergency contact destroys its recovery envelope — the one
  break-glass path that survives a private-key overwrite — so it must not happen
  from a single click on a row. The confirmation asks for the master password,
  which the store turns into a server-verified vault-key proof and never sends in
  the clear; the entered value stays inside this dialog and leaves only as the
  `confirm` payload.

  Lives in its own file per ADR-004 (and hydra gate-13): a dialog written inline
  in its parent couples presentation to the parent's lifecycle and cannot be
  reused. State the guard actually needs — the target id, the busy flag, the
  refusal message — stays with the parent view; this component only presents it.

  @spec openspec/changes/harden-vault-key-material-guards/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Revoke emergency access')"
		:open="open"
		size="normal"
		data-testid="emergency-revoke-dialog"
		@update:open="$emit('close')">
		<div class="emergency-revoke-confirm">
			<NcNoteCard type="warning">
				{{
					t(
						'keepiq',
						'This deletes the recovery envelope for this contact. They will no longer be able to break glass unless you re-establish them.',
					)
				}}
			</NcNoteCard>
			<NcPasswordField
				:modelValue="password"
				:label="t('keepiq', 'Your master password')"
				:disabled="revoking"
				@update:modelValue="$emit('update:password', $event)" />
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton :disabled="revoking" @click="$emit('close')">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="revoking || password === ''"
				data-testid="emergency-revoke-confirm"
				@click="$emit('confirm')">
				{{ revoking ? t('keepiq', 'Revoking…') : t('keepiq', 'Revoke') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcPasswordField } from '@nextcloud/vue'

export default {
	name: 'EmergencyRevokeDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcPasswordField,
	},

	props: {
		/** Whether the confirmation is visible. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The entered master password (v-model:password from the parent). */
		password: {
			type: String,
			default: '',
		},

		/** Whether a revoke is in flight, disabling the controls. */
		revoking: {
			type: Boolean,
			default: false,
		},

		/** A guard-refusal message to surface, or empty when there is none. */
		error: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'confirm', 'update:password'],
}
</script>
