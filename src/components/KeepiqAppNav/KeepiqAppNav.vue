<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  KeepiqAppNav — keepiq's own left rail (restyle Stage 7).

  The ONE justified custom shell component: CnAppNav verifiably cannot
  render trees, and the vault rail needs the folder tree. Every STATIC
  entry stays manifest-driven — this component renders `manifest.menu` by
  section/order exactly like CnAppNav does (captions →
  NcAppNavigationCaption; route items → NcAppNavigationItem `:to` so the
  `href$` e2e selectors keep working; `action: "user-settings"` → the
  cnOpenUserSettings inject; external `href` → a real anchor) and adds,
  after the FoldersCaption, the "All vaults" item hosting NavFolderTree.

  E2E-PARITY CONTRACT (documented, do not rename): the
  `cn-app-nav__footer-list` / `cn-app-nav__settings-list` class names and
  the `cn-nav-entry-<id>` / `cn-nav-settings` / `cn-nav-admin-settings`
  data-testids are CnAppNav's — navigation.spec.ts and
  page-surfaces.spec.ts (and the workflow specs touching UserSettings)
  pass unchanged against this component because of them. The few scoped
  list styles are copied locally for the same reason.

  Feature parity notes: the isAdmin-gated "Admin settings" link-out
  (absolute same-origin URL so it opens in a NEW tab, with an open-in-new
  marker) is replicated from CnAppNav — a custom #menu slot would
  otherwise silently drop it. Personal settings is NOT auto-prepended:
  keepiq opts out (nav.includePersonalSettings: false) and ships its own
  manifest entry with action "user-settings".

  The `.keepiq-shell--locked :deep(.app-navigation)` rule in App.vue keeps
  hiding this rail on the lock screen — it targets NcAppNavigation's root,
  which this component still renders.
-->
<template>
	<NcAppNavigation
		:aria-label="t('keepiq', 'Keepiq navigation')"
		data-testid="cn-nav">
		<template #list>
			<template v-for="item in mainItems" :key="item.id">
				<NcAppNavigationCaption
					v-if="item.type === 'caption'"
					:name="t('keepiq', item.label)"
					:data-testid="`cn-nav-caption-${item.id}`" />
				<NcAppNavigationItem
					v-else
					:name="t('keepiq', item.label)"
					:to="itemTo(item)"
					:href="itemHref(item)"
					:active="isActive(item)"
					:data-testid="`cn-nav-entry-${item.id}`"
					:data-cn-route="item.route"
					@click="onItemClick(item, $event)">
					<template v-if="item.icon" #icon>
						<CnIcon :name="item.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
			<!-- The Vaults caption + tree are OWNED BY THIS COMPONENT, not
			     the manifest: buildManifest drops menu entries without
			     route/href/action/children ("empty group shells"), which
			     eats a `type: "caption"` declaration before it ever renders.
			     Since the tree itself can only live here anyway, the caption
			     rides along. No "All vaults" host item — the caption already
			     names the group and a second link to the vault root would
			     double-highlight next to "All secrets". Revisit if the lib's
			     filter learns to spare captions. -->
			<NcAppNavigationCaption
				v-if="folderTree.length > 0"
				:name="t('keepiq', 'Vaults')"
				data-testid="cn-nav-caption-FoldersCaption" />
			<NavFolderTree
				v-if="folderTree.length > 0"
				:folders="folderTree"
				:highlightId="highlightFolderId"
				:ellipsisHighlightId="ellipsisHighlightId"
				@edit="editFolder = $event"
				@share="shareFolder = $event"
				@move="moveFolder = $event"
				@delete="onDeleteRequested" />
			<!-- Vault dialogs (restyle Stage 9), opened from the tree
			     entries' "⋮" menu. The store updates the folder list in
			     place, so the tree re-renders without a refetch. -->
			<FolderEditDialog
				v-if="editFolder"
				:folder="editFolder"
				@close="editFolder = null" />
			<MoveDialog
				v-if="moveFolder"
				subject="vault"
				:folder="moveFolder"
				@saved="onMoved"
				@close="moveFolder = null" />
			<!-- Share = team sharing, the same flow the list toolbar offers
			     for the open folder — here for any vault straight from the
			     rail. -->
			<TeamFolderDialog
				v-if="shareFolder"
				:open="true"
				:folderId="shareFolder.id"
				:folderName="shareFolder.name"
				@update:open="(value) => !value && (shareFolder = null)" />
			<!-- Deleting a vault with content goes through the resolution
			     protocol (per-subfolder keep/move/delete); an EMPTY vault
			     gets a plain confirm. -->
			<SubfolderResolutionDialog
				v-if="deleteFolder && deleteChildren && !deleteIsEmpty"
				:open="true"
				:folderId="deleteFolder.id"
				:children="deleteChildren"
				@deleted="onDeleted"
				@update:open="(value) => !value && resetDelete()" />
			<FolderDeleteConfirmDialog
				v-if="deleteFolder && deleteChildren && deleteIsEmpty"
				:folder="deleteFolder"
				@deleted="onDeleted"
				@close="resetDelete()" />
		</template>
		<template #footer>
			<!-- Footer-section entries live in NcAppNavigation's #footer slot —
			     OUTSIDE the scrollable list — so they stay visible above the
			     settings foldout no matter how long the main menu is (same
			     rationale as CnAppNav). -->
			<ul v-if="footerItems.length > 0" class="cn-app-nav__footer-list">
				<NcAppNavigationItem
					v-for="item in footerItems"
					:key="item.id"
					:name="t('keepiq', item.label)"
					:to="itemTo(item)"
					:href="itemHref(item)"
					:active="isActive(item)"
					:data-testid="`cn-nav-entry-${item.id}`"
					:data-cn-route="item.route"
					@click="onItemClick(item, $event)">
					<template v-if="item.icon" #icon>
						<CnIcon :name="item.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</ul>
			<NcAppNavigationSettings
				:name="t('keepiq', 'Settings')"
				data-testid="cn-nav-settings">
				<ul class="cn-app-nav__settings-list">
					<!-- Admin settings link-out, replicated from CnAppNav for
					     instance admins: the destination is Nextcloud's own
					     settings area, so it opens in a NEW tab (the href is
					     ABSOLUTE on purpose — NcAppNavigationItem only renders
					     target="_blank" for scheme-prefixed hrefs) and carries
					     an open-in-new marker. Visibility-only gate; the
					     server authorizes the settings page itself. -->
					<NcAppNavigationItem
						v-if="isAdmin"
						:name="t('keepiq', 'Admin settings')"
						:href="adminSettingsHref"
						data-testid="cn-nav-admin-settings">
						<template #icon>
							<ShieldAccountOutline :size="20" />
						</template>
						<template #counter>
							<OpenInNew
								:size="16"
								:title="t('keepiq', 'Opens in a new tab')" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						v-for="item in settingsItems"
						:key="item.id"
						:name="t('keepiq', item.label)"
						:to="itemTo(item)"
						:href="itemHref(item)"
						:active="isActive(item)"
						:data-testid="`cn-nav-entry-${item.id}`"
						:data-cn-route="item.route"
						@click="onItemClick(item, $event)">
						<template v-if="item.icon" #icon>
							<CnIcon :name="item.icon" :size="20" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { CnIcon } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppNavigation,
	NcAppNavigationCaption,
	NcAppNavigationItem,
	NcAppNavigationSettings,
} from '@nextcloud/vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import FolderDeleteConfirmDialog from '../../dialogs/FolderDeleteConfirmDialog.vue'
import FolderEditDialog from '../../dialogs/FolderEditDialog.vue'
import MoveDialog from '../../dialogs/MoveDialog.vue'
import SubfolderResolutionDialog from '../../modals/SubfolderResolutionDialog.vue'
import TeamFolderDialog from '../../modals/TeamFolderDialog.vue'
import NavFolderTree, { NAV_TREE_MAX_DEPTH } from './NavFolderTree.vue'
import { useFolderStore } from '../../store/modules/folder.js'
import { useSessionStore } from '../../store/modules/session.js'

/**
 * Keepiq's manifest-driven left rail with the recursive vault/folder tree.
 */
export default {
	name: 'KeepiqAppNav',

	components: {
		CnIcon,
		FolderDeleteConfirmDialog,
		FolderEditDialog,
		MoveDialog,
		NavFolderTree,
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		OpenInNew,
		ShieldAccountOutline,
		SubfolderResolutionDialog,
		TeamFolderDialog,
	},

	inject: {
		/**
		 * Opens the host app's NcAppSettingsDialog (provided by CnAppRoot).
		 * Defaults to a no-op so the rail still mounts standalone (tests).
		 */
		cnOpenUserSettings: { default: () => () => {} },
	},

	props: {
		/** The merged app manifest whose `menu[]` this rail renders. */
		manifest: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			/**
			 * The vault whose edit dialog is open (from the tree entries'
			 * "⋮" menu), or null. Restyle Stage 9.
			 */
			editFolder: null,
			/** The vault whose team-share dialog is open, or null. */
			shareFolder: null,
			/** The vault whose move dialog is open, or null. */
			moveFolder: null,
			/** The vault a delete was requested for, or null. */
			deleteFolder: null,
			/**
			 * The children payload (GET /folders/{id}/children) of the
			 * delete target — decides confirm vs the resolution protocol.
			 */
			deleteChildren: null,
		}
	},

	computed: {
		/**
		 * The folder store backing the rail's vault/folder tree.
		 *
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		folderStore() {
			return useFolderStore()
		},

		/**
		 * The session store: its lock state gates the tree fetch.
		 *
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		sessionStore() {
			return useSessionStore()
		},

		/**
		 * The nested vault/folder tree feeding NavFolderTree.
		 *
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		folderTree() {
			return this.folderStore.folderTree
		},

		/**
		 * Whether the delete target holds neither secrets nor subfolders —
		 * an empty folder gets a plain confirm; anything else goes through
		 * the resolution protocol.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		deleteIsEmpty() {
			const children = this.deleteChildren
			if (!children) {
				return false
			}
			return (
				(children.directSecretCount || 0) === 0
				&& (children.subfolders || []).length === 0
			)
		},

		/**
		 * The manifest menu, order-sorted (entries without order last).
		 *
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		sortedMenu() {
			return [...(this.manifest?.menu || [])].sort(
				(a, b) =>
					(a.order ?? Number.MAX_SAFE_INTEGER)
					- (b.order ?? Number.MAX_SAFE_INTEGER),
			)
		},

		/**
		 * Main-section entries (no `section`, or section "main").
		 *
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		mainItems() {
			return this.sortedMenu.filter(
				(item) => !item.section || item.section === 'main',
			)
		},

		/**
		 * Footer-section entries (Documentation, roadmap, activity, …).
		 *
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		footerItems() {
			return this.sortedMenu.filter((item) => item.section === 'footer')
		},

		/**
		 * Settings-foldout entries (Applications, Lock vault, …).
		 *
		 * @spec openspec/specs/menu-architecture/spec.md#requirement-setup-admin-entries-live-in-the-settings-foldout
		 */
		settingsItems() {
			return this.sortedMenu.filter((item) => item.section === 'settings')
		},

		/**
		 * Whether the current user administers the INSTANCE — gates the
		 * visibility of the Admin-settings link only; the settings page
		 * itself is authorized server-side.
		 *
		 * @return {boolean}
		 */
		isAdmin() {
			return getCurrentUser()?.isAdmin === true
		},

		/**
		 * Absolute same-origin URL of keepiq's Nextcloud admin settings.
		 * Absolute on purpose: NcAppNavigationItem opens only
		 * scheme-prefixed hrefs in a new tab.
		 *
		 * @return {string}
		 * @spec openspec/specs/menu-architecture/spec.md#requirement-setup-admin-entries-live-in-the-settings-foldout
		 */
		adminSettingsHref() {
			const origin =
				(typeof window !== 'undefined'
					&& window.location
					&& window.location.origin)
				|| ''
			return origin + generateUrl('/settings/admin/keepiq')
		},

		/**
		 * The active folder id from the route.
		 *
		 * @return {string|null}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		activeFolderId() {
			return this.$route?.params?.folderId || null
		},

		/**
		 * The folder id the tree highlights: the active folder itself while
		 * it is within the display cap, otherwise its DEEPEST VISIBLE
		 * ancestor — so the rail still shows where the user is when the
		 * route points below the cap. Cycle/depth guarded like every other
		 * parentId walk.
		 *
		 * @return {string|null}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		highlightFolderId() {
			const trail = this.activeFolderTrail
			if (trail.length === 0) {
				return null
			}
			if (trail.length <= NAV_TREE_MAX_DEPTH) {
				return this.activeFolderId
			}
			// Below the display cap no visible row IS the folder — the "…"
			// node under the deepest visible ancestor carries the highlight
			// instead (ellipsisHighlightId). Highlighting the ancestor too
			// would read as two selections for one folder.
			return null
		},

		/**
		 * The root-first id trail of the active folder (cycle/depth guarded
		 * like every other parentId walk); empty when no folder is active.
		 *
		 * @return {Array<string>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		activeFolderTrail() {
			const active = this.activeFolderId
			if (!active) {
				return []
			}
			const byId = new Map(this.folderStore.folders.map((f) => [f.id, f]))
			const trail = []
			const seen = new Set()
			let current = byId.get(active)
			while (current && !seen.has(current.id) && trail.length < 64) {
				seen.add(current.id)
				trail.unshift(current.id)
				current = current.parentId ? byId.get(current.parentId) : null
			}
			return trail
		},

		/**
		 * The capped node whose "…" child should render ACTIVE: set whenever
		 * the active folder lives anywhere below the display cap — the "…"
		 * stands for the whole hidden chain, so it (and only it) carries the
		 * selection, however deep the folder is.
		 *
		 * @return {string|null}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		ellipsisHighlightId() {
			const trail = this.activeFolderTrail
			if (trail.length <= NAV_TREE_MAX_DEPTH) {
				return null
			}
			return trail[NAV_TREE_MAX_DEPTH - 1]
		},
	},

	watch: {
		/**
		 * Refetch the tree when the vault unlocks — the locked shell hides
		 * the rail, and the folder list may have been unavailable before.
		 *
		 * @param {boolean} locked The new lock state.
		 */
		'sessionStore.isLocked': function (locked) {
			if (!locked) {
				this.fetchFoldersSafe()
			}
		},
	},

	/**
	 * Load the folder tree once at mount when the vault is unlocked.
	 *
	 * @return {void}
	 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
	 */
	mounted() {
		if (!this.sessionStore.isLocked) {
			this.fetchFoldersSafe()
		}
	},

	methods: {
		/**
		 * Router target for a manifest entry (route entries only) — `:to`
		 * keeps the rendered anchors' `href$` shape the e2e suite selects on.
		 *
		 * @param {object} item The menu entry.
		 * @return {object|null}
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		itemTo(item) {
			return item.route && !item.action ? { name: item.route } : null
		},

		/**
		 * Plain href for external entries (e.g. Documentation) — a real
		 * anchor, which NcAppNavigationItem opens in a new tab for
		 * scheme-prefixed URLs.
		 *
		 * @param {object} item The menu entry.
		 * @return {string|null}
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		itemHref(item) {
			return item.href && !item.action ? item.href : null
		},

		/**
		 * Whether a route entry matches the current route.
		 *
		 * @param {object} item The menu entry.
		 * @return {boolean}
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		isActive(item) {
			return Boolean(item.route) && this.$route?.name === item.route
		},

		/**
		 * Click dispatch: `action: "user-settings"` opens the host's
		 * settings dialog via the CnAppRoot inject and prevents default;
		 * everything else navigates natively through `:to`/`href`.
		 *
		 * @param {object} item The clicked entry.
		 * @param {Event} event The click event.
		 * @return {void}
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		onItemClick(item, event) {
			if (item.action === 'user-settings') {
				if (event && typeof event.preventDefault === 'function') {
					event.preventDefault()
				}
				this.cnOpenUserSettings()
			}
		},

		/**
		 * Fetch the folder list without ever throwing into the rail — a
		 * failed fetch costs the tree, never the navigation.
		 *
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		fetchFoldersSafe() {
			this.folderStore.fetchFolders().catch(() => {})
		},

		/**
		 * A delete was requested from a vault's "⋮" menu: load the folder's
		 * children payload first, because it decides which surface opens —
		 * a plain confirm for an empty folder, the per-subfolder resolution
		 * protocol for anything with content.
		 *
		 * @param {object} node The vault node.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-folder-children
		 */
		async onDeleteRequested(node) {
			this.deleteFolder = node
			this.deleteChildren = null
			try {
				this.deleteChildren = await this.folderStore.fetchChildren(node.id)
			} catch {
				// No payload, no dialog — fail quiet rather than offering a
				// delete whose consequences we could not describe.
				this.deleteFolder = null
			}
		},

		/**
		 * Close whichever delete surface is open and clear its state.
		 *
		 * @return {void}
		 * @spec exclude Dialog-host state reset; no domain behaviour.
		 */
		resetDelete() {
			this.deleteFolder = null
			this.deleteChildren = null
		},

		/**
		 * The resolution dialog finished: clear state and leave the deleted
		 * folder's route if it was open.
		 *
		 * @param {string} folderId The deleted folder id.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		onDeleted(folderId) {
			this.resetDelete()
			this.leaveDeletedRoute(folderId)
		},

		/**
		 * A move landed: refetch (a re-parent can change what the display
		 * cap hides) and close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		onMoved() {
			this.moveFolder = null
			this.fetchFoldersSafe()
		},

		/**
		 * Navigate back to the vault root when the just-deleted folder was
		 * the OPEN one — its route would otherwise 404 into an empty list.
		 *
		 * @param {string} folderId The deleted folder id.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		leaveDeletedRoute(folderId) {
			if (this.$route?.params?.folderId === folderId) {
				this.$router?.push({ name: 'SecretList' })
			}
		},
	},
}
</script>

<style scoped>
/* Copied from CnAppNav (e2e-parity contract, see the header comment). */
.cn-app-nav__footer-list {
	list-style: none;
	margin: 0;
	padding: 0;
	flex-shrink: 0 !important;
	overflow: visible !important;
}

.cn-app-nav__settings-list {
	list-style: none;
	margin: 0;
	padding-inline-start: 5px;
}
</style>
