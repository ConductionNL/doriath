<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The one rendering of "which vault does this live in" outside the vault's
  own page (2026-09-03, per Remko, Proton pattern). Two shapes, one color
  resolution, so the glyphs cannot drift:

  - `dot`: the vault's icon on a tinted circle, name as tooltip + visually
    hidden text — the All-secrets rows, where a cross-vault list otherwise
    gives no clue where a secret lives.
  - `tag`: icon + vault name in the vault's color — the detail sidebar,
    under the secret's title, like Proton's vault tag.
-->
<template>
	<span
		v-if="vault"
		class="vault-indicator"
		:class="`vault-indicator--${variant}`"
		:style="variant === 'dot' ? tintStyle : undefined"
		:title="variant === 'dot' ? vault.name : undefined">
		<component
			:is="iconComponent"
			:size="variant === 'dot' ? 14 : 16"
			:fillColor="glyphColor" />
		<span
			v-if="variant === 'tag'"
			class="vault-indicator__name"
			:style="nameStyle"
			>{{ vault.name }}</span
		>
		<span v-else class="vault-indicator__sr">{{ vault.name }}</span>
	</span>
</template>

<script>
import {
	currentTheme,
	folderColorTint,
	resolveFolderColor,
	resolveFolderIcon,
} from '@conduction/nextcloud-vue'
import Safe from 'vue-material-design-icons/Safe.vue'

/**
 * A secret's vault, as a compact glyph (`dot`) or an icon+name tag (`tag`),
 * rendered with the vault's own Stage 9 icon and color. Renders nothing
 * without a vault, so callers can pass their resolution result straight in.
 */
export default {
	name: 'VaultIndicator',

	props: {
		/** The vault record ({id, name, customIcon, customColor}), or null. */
		vault: {
			type: Object,
			default: null,
		},

		/** 'dot' (tinted circle, name as tooltip) or 'tag' (icon + name). */
		variant: {
			type: String,
			default: 'dot',
			validator: (value) => ['dot', 'tag'].includes(value),
		},
	},

	computed: {
		/**
		 * The vault's picked icon (restyle Stage 9); Safe for unset — and
		 * for UNKNOWN keys, which keeps older bundles forward-compatible
		 * with newer catalogs.
		 *
		 * @return {object} An icon component.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		iconComponent() {
			return resolveFolderIcon(this.vault.customIcon) ?? Safe
		},

		/**
		 * The glyph's fill for the ACTIVE theme (reactive — a live
		 * light/dark flip swaps the variant). ALWAYS a string:
		 * 'currentColor' for unset colors — an explicit null fill-color
		 * strips the SVG fill attribute, which renders BLACK regardless of
		 * theme.
		 *
		 * @return {string} A hex color or 'currentColor'.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		glyphColor() {
			return (
				resolveFolderColor(this.vault.customColor, currentTheme())
				?? 'currentColor'
			)
		},

		/**
		 * The dot's circle: the SAME resolved color at low alpha, so glyph
		 * and circle stay in lockstep across themes. Undefined keeps the
		 * neutral background from the stylesheet.
		 *
		 * @return {object|undefined} An inline style, or undefined.
		 * @spec exclude Presentation-only style derivation for the tinted circle.
		 */
		tintStyle() {
			const tint = folderColorTint(this.vault.customColor, currentTheme())
			return tint ? { backgroundColor: tint } : undefined
		},

		/**
		 * The tag's text color: the vault's color when set, otherwise the
		 * inherited text color.
		 *
		 * @return {object|undefined} An inline style, or undefined.
		 * @spec exclude Presentation-only style derivation for the tag text.
		 */
		nameStyle() {
			return this.glyphColor === 'currentColor'
				? undefined
				: { color: this.glyphColor }
		},
	},
}
</script>

<style scoped>
.vault-indicator {
	display: inline-flex;
	align-items: center;
	flex-shrink: 0;
}

.vault-indicator--dot {
	justify-content: center;
	width: 26px;
	height: 26px;
	border-radius: 50%;
	background-color: var(--color-background-hover);
}

.vault-indicator--tag {
	gap: 6px;
	font-size: 12.5px;
	font-weight: 600;
}

/* Screen-reader-only vault name for the dot (its visual name is the
   tooltip); same clip pattern as Nextcloud's hidden-visually. */
.vault-indicator__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
</style>
