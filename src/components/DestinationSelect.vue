<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The one destination picker for "move" flows: pick a vault or a folder, with
  each option carrying the same glyph the nav rail draws for it.

  It exists because three dialogs had each grown their own copy of this
  control — one with per-vault icon/colour/tint slots, two with bare lists of
  path strings — so the same choice looked different depending on how you got
  there.

  Options are ordered as a TREE: a vault, then its own folders indented
  beneath it, then the next vault. The flat store order put every vault first
  and every folder afterwards, which read as two unrelated groups and left a
  folder pages away from the vault it belongs to. A deep tree makes for a long
  list; that is the honest trade, and scrolling is the right answer to it.

  The DIFFERENCE between the flows is the candidate set, and that is all:

    mode="folders"  every vault AND every folder — where a secret can go.
                    There is no "vault root" option: a secret always lives in
                    a vault, and a rootless secret has nowhere to be shown.
    mode="vaults"   vaults only — where a vault's CONTENTS can go. Vaults
                    themselves never re-parent (team decision), so a vault is
                    never offered a folder as a destination.

  The option list TELEPORTS (NcSelect's default), so it floats over the dialog
  instead of being trapped in its scroll box. It was briefly forced inline: an
  older @nextcloud/vue painted a teleported list BEHIND NcDialog, making the
  control dead — options in the DOM, every click landing on the dialog (CI
  runs 30798827764, 30800957925, 30804327498; raising z-index to 10002 in
  30804327498 and 30805835374 changed nothing, because the two ended up in
  different stacking contexts). Forcing it inline then clipped it to a sliver
  of the dialog's 52px content box, and reserving room for it made every move
  dialog 300px tall and ugly. On 9.11.0 NcSelect positions a teleported menu
  with floating-ui and it renders correctly over the dialog, which is both the
  original design and the better look — so it teleports again and the room
  hack is gone. If a dead control ever comes back, that is the history.
-->
<template>
	<NcSelect
		:modelValue="selected"
		:options="options"
		:reduce="(opt) => opt.value"
		:inputLabel="label"
		:disabled="disabled"
		:clearable="false"
		class="destination-select"
		data-testid="destination-select"
		@update:modelValue="$emit('update:modelValue', $event)">
		<!-- Identity AND place: the glyph in its own colour on the tinted
		     circle exactly as the rail renders it, indented to show which
		     vault a folder belongs to. -->
		<template #option="option">
			<span class="destination-select__option" :style="indentStyle(option)">
				<span class="destination-select__glyph" :style="glyphStyle(option)">
					<component
						:is="glyphIcon(option)"
						:size="16"
						:fillColor="glyphColor(option)" />
				</span>
				{{ option.label }}
			</span>
		</template>
		<!-- The selected row is never indented: it stands alone, so leading
		     whitespace would only look like a rendering fault. -->
		<template #selected-option="option">
			<span class="destination-select__option">
				<span class="destination-select__glyph" :style="glyphStyle(option)">
					<component
						:is="glyphIcon(option)"
						:size="16"
						:fillColor="glyphColor(option)" />
				</span>
				{{ option.label }}
			</span>
		</template>
	</NcSelect>
</template>

<script>
import {
	currentTheme,
	folderColorTint,
	resolveFolderColor,
	resolveFolderIcon,
} from '@conduction/nextcloud-vue'
import { NcSelect } from '@nextcloud/vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Safe from 'vue-material-design-icons/Safe.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { destinationRows } from '../utils/vaultList.js'

/**
 * Destination picker for the move dialogs. Builds its own candidate list from
 * the folder store so both callers offer the same things, labelled and
 * glyphed the same way.
 */
export default {
	name: 'DestinationSelect',

	components: {
		NcSelect,
	},

	props: {
		/** The selected destination id. */
		modelValue: {
			type: String,
			default: null,
		},

		/**
		 * Which candidates to offer: `folders` (every vault and folder) or
		 * `vaults` (vaults only).
		 */
		mode: {
			type: String,
			default: 'folders',
			validator: (value) => ['folders', 'vaults'].includes(value),
		},

		/**
		 * A subtree to leave out — the vault whose contents are being moved
		 * (it cannot receive them) or a folder being re-parented (it cannot
		 * move inside itself). Descendants go with it.
		 */
		excludeId: {
			type: String,
			default: null,
		},

		/** The control's label. */
		label: {
			type: String,
			required: true,
		},

		/** Whether the control is inert. */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * `modelValue` as vue-select expects it.
		 *
		 * @return {string|null} The selected id.
		 * @spec exclude Prop pass-through; no domain behaviour.
		 */
		selected() {
			return this.modelValue
		},

		/**
		 * The candidate destinations for this mode, tree-ordered.
		 *
		 * Labels are plain names, not "A / B / C" paths: the indentation
		 * already says where an entry sits, and a path repeated on every row
		 * of a deep tree is noise.
		 *
		 * @return {Array<{value: string, label: string, depth: number, customIcon: ?string, customColor: ?string}>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		options() {
			const rows = destinationRows(useFolderStore().folders, this.excludeId)
			const candidates =
				this.mode === 'vaults' ? rows.filter((row) => row.depth === 0) : rows
			return candidates.map((row) => ({
				value: row.id,
				label: row.name,
				depth: row.depth,
				customIcon: row.customIcon ?? null,
				customColor: row.customColor ?? null,
			}))
		},
	},

	/**
	 * Hydrate the folder store if the host opened this picker before the nav
	 * had loaded (SecretMoveDialog relied on doing this itself).
	 *
	 * The failure is swallowed deliberately: a destination picker that cannot
	 * refresh the candidate list should offer what the store already holds,
	 * not reject into its host's mount and take the dialog down with it.
	 *
	 * @return {Promise<void>}
	 * @spec exclude Store hydration; no domain behaviour.
	 */
	async mounted() {
		const folderStore = useFolderStore()
		if (folderStore.folders.length === 0) {
			await folderStore.fetchFolders().catch(() => {})
		}
	},

	methods: {
		t,

		/**
		 * How far to indent an option: one step per level, so a folder sits
		 * under the vault it belongs to.
		 *
		 * @param {object} option The picker option.
		 * @return {object} A style object.
		 * @spec exclude Presentation; no domain behaviour.
		 */
		indentStyle(option) {
			return { paddingInlineStart: (option.depth || 0) * 16 + 'px' }
		},

		/**
		 * The option's glyph component. A VAULT falls back to the safe icon
		 * and a folder to the folder outline — chosen by depth, not by picker
		 * mode, so the folders list still shows vaults as vaults.
		 *
		 * @param {object} option The picker option.
		 * @return {object} A Vue component.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		glyphIcon(option) {
			const fallback = (option.depth || 0) === 0 ? Safe : FolderOutline
			return resolveFolderIcon(option.customIcon) ?? fallback
		},

		/**
		 * The glyph's fill for the ACTIVE theme. Always a string — an explicit
		 * null fill-color strips the SVG fill attribute and renders black.
		 *
		 * @param {object} option The picker option.
		 * @return {string} A hex color or 'currentColor'.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		glyphColor(option) {
			return (
				resolveFolderColor(option.customColor, currentTheme())
				?? 'currentColor'
			)
		},

		/**
		 * The tinted circle behind the glyph (same-hex tint, as the rail
		 * renders vaults). Undefined keeps the neutral circle.
		 *
		 * @param {object} option The picker option.
		 * @return {object|undefined} A style object or undefined.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		glyphStyle(option) {
			const tint = folderColorTint(option.customColor, currentTheme())
			return tint ? { backgroundColor: tint } : undefined
		},
	},
}
</script>

<style scoped>
/* Identity inside the picker: glyph + label per option, the glyph on the
   same tinted circle the rail uses. The option list itself needs no sizing
   rules here — it teleports and floats over the dialog, so there is
   nothing to clip it. */
.destination-select__option {
	display: flex;
	align-items: center;
	gap: 8px;
}

.destination-select__glyph {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background-color: var(--color-background-hover);
	flex-shrink: 0;
}
</style>
