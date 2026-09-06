<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The one type→glyph rendering for a secret's type. List rows, table cells
  and cards all draw the type through this component, so the icon map cannot
  drift apart between views again — the cards and table shipping with no type
  icon at all (fix-brief bugs 8+9) is exactly that drift.
-->
<template>
	<component :is="iconComponent" :size="size" />
</template>

<script>
import CardAccountDetailsOutline from 'vue-material-design-icons/CardAccountDetailsOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import Console from 'vue-material-design-icons/Console.vue'
import CreditCardOutline from 'vue-material-design-icons/CreditCardOutline.vue'
import Database from 'vue-material-design-icons/Database.vue'
import Fingerprint from 'vue-material-design-icons/Fingerprint.vue'
import Key from 'vue-material-design-icons/Key.vue'
import NoteText from 'vue-material-design-icons/NoteText.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { typeIconName } from '../utils/favicon.js'

/**
 * The Material Design icon for a secret's type, resolved through the type
 * store and the shared type→icon map in `utils/favicon.js`.
 */
export default {
	name: 'SecretTypeIcon',

	components: {
		CardAccountDetailsOutline,
		ClockOutline,
		CodeTags,
		Console,
		CreditCardOutline,
		Database,
		Fingerprint,
		Key,
		NoteText,
		ShieldCheck,
	},

	props: {
		/** The secret's typeId, resolved against the type store. */
		typeId: {
			type: String,
			default: null,
		},

		/** Icon size in pixels. */
		size: {
			type: Number,
			default: 24,
		},
	},

	computed: {
		/**
		 * The icon component name for the secret's type; the login key
		 * glyph when the type is unknown.
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 */
		iconComponent() {
			const type = useSecretTypeStore().typesById[this.typeId]
			return typeIconName(type ? type.name : 'login')
		},
	},
}
</script>
