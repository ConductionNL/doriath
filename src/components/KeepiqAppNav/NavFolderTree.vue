<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  NavFolderTree — recursive folder tree for the left rail (restyle Stage 7).

  Renders folderStore.folderTree nodes as NcAppNavigationItem rows, each
  navigating to its folder's list page. DISPLAY CAP (Option C): folders
  render to depth 5; the next hidden level shows as ONE child node named
  "…" standing in the chain. Clicking "…" navigates INTO the folder it
  represents when the deepest rendered folder has exactly one hidden
  child; with more than one hidden child it opens the deepest rendered
  folder's own page instead — its subfolder rows (Stage 6) list all
  children, and rows + breadcrumbs take over from there.
-->
<template>
	<ul
		class="keepiq-nav-tree"
		:data-testid="depth === 0 ? 'nav-folder-tree' : undefined">
		<NcAppNavigationItem
			v-for="node in folders"
			:key="node.id"
			:name="node.name"
			:to="{ name: 'SecretListFolder', params: { folderId: node.id } }"
			:active="node.id === highlightId"
			:allowCollapse="hasVisibleChildren(node)"
			:open="openState[node.id] ?? true"
			:data-testid="`nav-folder-${node.id}`"
			@update:open="openState[node.id] = $event">
			<!-- Root-level entries ARE the vaults (Stage 5 terminology), so
			     they carry the safe glyph — or the user's OWN icon + color
			     on a Proton-style tinted circle derived from the SAME color
			     (restyle Stage 9); only nested entries are plain folders. -->
			<template #icon>
				<span
					v-if="depth === 0"
					class="keepiq-nav-tree__vault-glyph"
					:style="vaultGlyphStyle(node)">
					<component
						:is="vaultIcon(node)"
						:size="18"
						:fillColor="vaultColor(node)" />
				</span>
				<FolderOutline v-else :size="18" />
			</template>
			<!-- Vault-level actions (Stage 9): edit/share/move/delete in the
			     NcAppNavigationItem-native actions menu, hosted by
			     KeepiqAppNav. Proton's dialog approach, touch-friendly.
			     The trigger is the VERTICAL dots (per review) — NcActions
			     defaults to horizontal, the menu-icon slot overrides it. -->
			<template v-if="depth === 0" #menu-icon>
				<DotsVertical :size="20" />
			</template>
			<template v-if="depth === 0" #actions>
				<NcActionButton
					:data-testid="`nav-folder-edit-${node.id}`"
					:closeAfterClick="true"
					@click="$emit('edit', node)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('keepiq', 'Edit vault') }}
				</NcActionButton>
				<NcActionButton
					:data-testid="`nav-folder-share-${node.id}`"
					:closeAfterClick="true"
					@click="$emit('share', node)">
					<template #icon>
						<ShareVariantOutline :size="20" />
					</template>
					{{ t('keepiq', 'Share vault') }}
				</NcActionButton>
				<NcActionButton
					:data-testid="`nav-folder-move-${node.id}`"
					:closeAfterClick="true"
					@click="$emit('move', node)">
					<template #icon>
						<FolderMove :size="20" />
					</template>
					{{ t('keepiq', 'Move vault contents') }}
				</NcActionButton>
				<NcActionSeparator />
				<NcActionButton
					:data-testid="`nav-folder-delete-${node.id}`"
					:closeAfterClick="true"
					@click="$emit('delete', node)">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					{{ t('keepiq', 'Delete vault') }}
				</NcActionButton>
			</template>
			<NavFolderTree
				v-if="hasVisibleChildren(node)"
				:folders="node.children"
				:depth="depth + 1"
				:highlightId="highlightId"
				:ellipsisHighlightId="ellipsisHighlightId" />
			<!-- Router link on purpose (navigates INTO the hidden folder it
			     stands for). ACTIVE whenever the open folder lives anywhere
			     in the chain this "…" hides (KeepiqAppNav's
			     ellipsisHighlightId) — ancestor rows stay unhighlighted so
			     only ONE row reads as selected. -->
			<NcAppNavigationItem
				v-else-if="hasHiddenChildren(node)"
				name="…"
				:to="ellipsisTarget(node)"
				:active="node.id === ellipsisHighlightId"
				:data-testid="`nav-folder-ellipsis-${node.id}`" />
		</NcAppNavigationItem>
	</ul>
</template>

<script>
import {
	currentTheme,
	folderColorTint,
	resolveFolderColor,
	resolveFolderIcon,
} from '@conduction/nextcloud-vue'
import {
	NcActionButton,
	NcActionSeparator,
	NcAppNavigationItem,
} from '@nextcloud/vue'
import DotsVertical from 'vue-material-design-icons/DotsVertical.vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Safe from 'vue-material-design-icons/Safe.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

/**
 * Maximum folder depth the rail renders. Deeper levels are reachable
 * through the "…" stand-in node plus the list page's subfolder rows and
 * breadcrumbs (Stage 6) — the rail stays legible, nothing becomes
 * unreachable.
 *
 * @type {number}
 */
export const NAV_TREE_MAX_DEPTH = 5

/**
 * Recursive NcAppNavigationItem folder tree with a display cap.
 */
export default {
	name: 'NavFolderTree',

	components: {
		NcActionButton,
		NcActionSeparator,
		NcAppNavigationItem,
		DotsVertical,
		FolderMove,
		FolderOutline,
		Pencil,
		ShareVariantOutline,
		TrashCanOutline,
	},

	props: {
		/** The folder nodes at this level (each with a `children` array). */
		folders: {
			type: Array,
			default: () => [],
		},

		/** This level's depth: 0 = the top-level vaults. */
		depth: {
			type: Number,
			default: 0,
		},

		/**
		 * The folder id to highlight. Null when the ACTIVE folder is deeper
		 * than the display cap — the "…" node carries the selection then
		 * (ellipsisHighlightId), so the rail still shows where the user is
		 * without lighting an ancestor row.
		 */
		highlightId: {
			type: String,
			default: null,
		},

		/**
		 * The capped node whose "…" child renders ACTIVE: set by the caller
		 * whenever the open folder lives anywhere in that node's hidden
		 * chain (below the display cap).
		 */
		ellipsisHighlightId: {
			type: String,
			default: null,
		},
	},

	emits: ['edit', 'share', 'move', 'delete'],

	data() {
		return {
			/**
			 * Per-node collapse state (open by default so the whole tree is
			 * visible; a user's toggle is remembered for the component's
			 * lifetime).
			 */
			openState: {},
		}
	},

	methods: {
		t,

		/**
		 * The glyph a depth-0 vault entry renders: the user's picked icon
		 * (restyle Stage 9), with the Safe default for unset — and for
		 * UNKNOWN keys, which keeps older bundles forward-compatible with
		 * values written by newer catalogs.
		 *
		 * @param {object} node The vault node.
		 * @return {object} An icon component.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		vaultIcon(node) {
			return resolveFolderIcon(node.customIcon) ?? Safe
		},

		/**
		 * Whether the entry renders as the rail's ACTIVE row — where NC
		 * paints a solid primary background, which a translucent tint and
		 * a colored glyph would sink into.
		 *
		 * @param {object} node The vault node.
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		isHighlighted(node) {
			return node.id === this.highlightId
		},

		/**
		 * The vault glyph's fill for the ACTIVE theme (reactive — a live
		 * light/dark flip swaps the variant without a reload). On the
		 * HIGHLIGHTED row the glyph follows the row's own text color like
		 * every other nav glyph (the collapse chevron included) — an
		 * opaque-disc-keeps-the-color variant was tried and rejected in
		 * review: the disc read as a stray pill on the highlight. Color
		 * identity shows at rest and on hover. ALWAYS a string:
		 * 'currentColor' for unset colors — an explicit null fill-color
		 * strips the SVG fill attribute entirely, which renders BLACK
		 * regardless of theme.
		 *
		 * @param {object} node The vault node.
		 * @return {string} A hex color or 'currentColor'.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		vaultColor(node) {
			return (
				resolveFolderColor(node.customColor, currentTheme())
				?? 'currentColor'
			)
		},

		/**
		 * The circle behind the vault glyph: the Proton-style translucent
		 * tint of the SAME resolved color (the 53a36006 approach — one
		 * color source, glyph and circle can never disagree across
		 * themes). No circle on the HIGHLIGHTED row (the glyph is plain
		 * there, see vaultColor) and none for uncolored vaults.
		 *
		 * @param {object} node The vault node.
		 * @return {object|undefined} A style object or undefined.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		vaultGlyphStyle(node) {
			const theme = currentTheme()
			const hasColor = resolveFolderColor(node.customColor, theme) !== null
			if (!hasColor) {
				// Colorless vaults: no circle anywhere; the glyph follows the
				// row's text color (white on the highlight, via the CSS
				// icon-column rule below).
				return undefined
			}
			if (this.isHighlighted(node)) {
				// Selected row (team decision, settled after trying both a
				// plain white glyph and a translucent tint): the circle goes
				// OPAQUE in the theme's main background — a white disc in
				// light mode, a dark disc in dark mode — with the vault's
				// COLORED glyph on it. That recreates exactly the rest-state
				// foreground/background pairing (light palette variants on a
				// light surface, dark variants on a dark one), so the color
				// identity survives selection at unchanged contrast.
				return { backgroundColor: 'var(--color-main-background)' }
			}
			return {
				backgroundColor: folderColorTint(node.customColor, theme),
			}
		},

		/**
		 * Whether `node`'s children render as a nested tree (still within
		 * the display cap).
		 *
		 * @param {object} node The folder node.
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		hasVisibleChildren(node) {
			return (
				(node.children || []).length > 0
				&& this.depth + 1 < NAV_TREE_MAX_DEPTH
			)
		},

		/**
		 * Whether `node` sits at the cap with children the rail hides —
		 * the case the "…" stand-in node covers.
		 *
		 * @param {object} node The folder node.
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		hasHiddenChildren(node) {
			return (
				(node.children || []).length > 0
				&& this.depth + 1 >= NAV_TREE_MAX_DEPTH
			)
		},

		/**
		 * Where the "…" node navigates: INTO the single hidden child when
		 * there is exactly one (it stands in the chain), otherwise to the
		 * deepest RENDERED folder's own page, whose subfolder rows list all
		 * the hidden children.
		 *
		 * @param {object} node The capped folder node.
		 * @return {object} A SecretListFolder router target.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		ellipsisTarget(node) {
			const children = node.children || []
			const folderId = children.length === 1 ? children[0].id : node.id
			return { name: 'SecretListFolder', params: { folderId } }
		},
	},
}
</script>

<style scoped>
/* NcAppNavigationItem already wraps slotted children in its own
   `ul.app-navigation-entry__children` (which owns the per-level indent),
   so this wrapper exists only to carry the `nav-folder-tree` testid and
   the recursion — `display: contents` removes its box entirely and lets
   the NC list styling apply as if the items were direct children. */
.keepiq-nav-tree {
	display: contents;
}

/* The Proton-style tinted circle behind a colored vault's glyph. Sized to
   sit inside NcAppNavigationItem's icon column without growing the row. */
.keepiq-nav-tree__vault-glyph {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 30px;
	height: 30px;
	border-radius: 50%;
}

/* Icon-column color on the ACTIVE row, keyed to the row's own `.active`
   class (which has MORE sources than the highlightId prop —
   NcAppNavigationItem also activates through vue-router's own link
   matching). NcAppNavigationItem's modern active rule pins the LINK to
   --color-main-text (black) with !important while the instance themes
   paint the row SOLID PRIMARY and whiten only the label — so inherited
   currentColor resolves black on a blue row. The primary-contrast token
   makes colorless vault glyphs and the nested FolderOutline white there.
   COLORED vault glyphs are untouched: their hex rides the svg fill
   attribute, which inherited color never overrides — on the selected row
   they render on the opaque main-background disc instead (see
   vaultGlyphStyle), keeping the color identity visible. */
.keepiq-nav-tree :deep(.app-navigation-entry.active .app-navigation-entry-icon) {
	color: var(--color-primary-element-text) !important;
}

/* The actions trigger on the ACTIVE row: the default button chrome reads
   as a stray light pill on the row highlight (whether the server renders
   the solid legacy highlight or the tinted modern one) — make it
   transparent and let the dots follow the row's own text color; hover
   feedback comes from the text color at low alpha, which works on both
   highlight generations. */
.keepiq-nav-tree
	:deep(.app-navigation-entry.active .app-navigation-entry__utils .button-vue) {
	background-color: transparent !important;
	/* NOT `inherit`: NC's legacy-active rule whitens only the LINK element,
	   so the utils area inherits the entry div's default (main-text, black
	   on the blue row). The collapse chevron is white because NC hands it
	   the tertiary-on-primary variant — the menu toggle never gets that,
	   so it takes the same contrast token explicitly. */
	color: var(--color-primary-element-text) !important;
}

.keepiq-nav-tree
	:deep(
		.app-navigation-entry.active .app-navigation-entry__utils .button-vue:hover
	),
.keepiq-nav-tree
	:deep(
		.app-navigation-entry.active
			.app-navigation-entry__utils
			.button-vue:focus-visible
	) {
	background-color: color-mix(in srgb, currentColor 20%, transparent) !important;
}
</style>
