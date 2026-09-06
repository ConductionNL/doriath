<template>
	<div class="secret-list-view">
		<!-- Single pane (restyle Stage 7): folder navigation lives in the app
		     nav's folder tree (KeepiqAppNav/NavFolderTree) and in the list's
		     own subfolder rows + breadcrumbs (Stage 6); the in-page folder
		     sidebar is gone. -->
		<div class="secret-list-view__main">
			<ExportDialog
				:open="exportOpen"
				:secrets="decryptedSecrets"
				:folders="folders"
				@update:open="exportOpen = $event" />
			<CxpTransferDialog
				:open="cxpOpen"
				:secrets="decryptedSecrets"
				:folders="folders"
				@update:open="cxpOpen = $event"
				@openImport="importOpen = true" />
			<GdprExportDialog
				:open="gdprOpen"
				:secrets="decryptedSecrets"
				:folders="folders"
				@update:open="gdprOpen = $event" />
			<AccountDeletionDialog
				:open="deletionOpen"
				@update:open="deletionOpen = $event"
				@exportFirst="onExportFirst" />
			<ImportWizardDialog
				:open="importOpen"
				@update:open="importOpen = $event"
				@imported="onImported" />
			<TeamFolderDialog
				:open="teamFolderOpen"
				:folderId="selectedFolderId"
				:folderName="selectedFolderName"
				@update:open="teamFolderOpen = $event" />

			<!-- Ephemeral send (ephemeral-send §5). -->
			<NewSendDialog
				v-if="newSendOpen"
				:open="true"
				@close="newSendOpen = false" />
			<MySendsDialog
				v-if="mySendsOpen"
				:open="true"
				@close="mySendsOpen = false" />

			<!-- Bulk action dialogs (bulk-actions §3). -->
			<BulkMoveDialog
				v-if="bulkDialog === 'move'"
				:open="true"
				@close="closeBulkDialog"
				@done="onBulkDone" />
			<BulkDeleteDialog
				v-if="bulkDialog === 'delete'"
				:open="true"
				@close="closeBulkDialog"
				@done="onBulkDone" />
			<BulkShareDialog
				v-if="bulkDialog === 'share'"
				:open="true"
				@close="closeBulkDialog"
				@done="onBulkDone" />
			<BulkTeamFolderDialog
				v-if="bulkDialog === 'teamFolder'"
				:open="true"
				@close="closeBulkDialog"
				@done="onBulkDone" />

			<!-- showFormDialog OFF: CnIndexPage also watches ?action=create
			     and would open its own generic schema-form dialog OVER the
			     registry's SecretCreateDialog (the consumeCreateAction
			     watcher below). The prop is the library's documented
			     "consumer manages its own dialog" opt-out; the @add path is
			     unaffected because this view listens to it. -->
			<CnIndexPage
				viewMode="list"
				:availableViewModes="['list', 'cards', 'table']"
				listLabel="List"
				:showFormDialog="false"
				:selectedIds="bulkStore.selectedIds"
				rowClickToView
				:objects="listObjects"
				:schema="listSchema"
				:columnOverrides="listColumnOverrides"
				:loading="loading || folderSwitching"
				:pagination="pagination"
				:title="pageTitle"
				:addLabel="offlineReadOnly ? '' : t('keepiq', 'New secret')"
				addIcon="Plus"
				inlineSearch
				:searchValue="searchTerm"
				:searchPlaceholder="t('keepiq', 'Search secrets')"
				rowKey="id"
				:emptyText="t('keepiq', 'No secrets found')"
				:refreshing="loading"
				:showMassImport="false"
				:showMassExport="false"
				:showMassCopy="false"
				:showMassDelete="false"
				@refresh="reload"
				@add="openCreateSecret"
				@search="onSearch"
				@select="onLibrarySelect"
				@rowClick="onRowOpen"
				@pageChanged="goToPage">
				<!-- ONE actions menu (Stage 8 toolbar decision): everything
				     that lived in the title row's Refresh button + "More
				     actions" overflow, and the inline Select-all checkbox,
				     folds into the actions bar's own overflow menu.
				     #action-items renders right after the bar's built-in
				     Refresh entry (already wired via @refresh/:refreshing).
				     The library's generic mass Import/Export/Copy/Delete
				     entries are turned OFF above — they bypass keepiq's
				     encrypted import/export flows and duplicated the "My
				     data" entries. Order: list controls (Select all), the
				     Stage-5 toolbar actions, the "My data" entries
				     (secret-export-gdpr §6.5), then the secret-type filter
				     (passkey-item-type §3.3). data-testids and disabled
				     conditions are unchanged. -->
				<template #action-items>
					<!-- Select-all for the current filtered/paginated view. -->
					<NcActionCheckbox
						:modelValue="allCurrentSelected"
						:disabled="secrets.length === 0"
						data-testid="bulk-select-all"
						@update:modelValue="onSelectAll">
						{{ t('keepiq', 'Select all') }}
					</NcActionCheckbox>

					<NcActionSeparator />
					<NcActionButton
						v-for="item in toolbarItems"
						:key="item.id"
						:disabled="item.disabled"
						:data-testid="item.testid"
						:closeAfterClick="true"
						@click="item.run()">
						<template #icon>
							<component :is="item.icon" :size="20" />
						</template>
						{{ item.label }}
					</NcActionButton>

					<!-- Data export / GDPR / deletion entry points
					     (secret-export-gdpr §6.5). -->
					<NcActionSeparator />
					<NcActionButton
						data-testid="open-new-send"
						:closeAfterClick="true"
						@click="newSendOpen = true">
						{{ t('keepiq', 'New ephemeral send') }}
					</NcActionButton>
					<NcActionButton
						data-testid="open-my-sends"
						:closeAfterClick="true"
						@click="mySendsOpen = true">
						{{ t('keepiq', 'My ephemeral sends') }}
					</NcActionButton>
					<NcActionButton :closeAfterClick="true" @click="openExport">
						{{ t('keepiq', 'Export data') }}
					</NcActionButton>
					<NcActionButton
						:disabled="vaultLocked"
						data-testid="cxp-transfer"
						:closeAfterClick="true"
						@click="openCxp">
						{{ t('keepiq', 'Encrypted transfer (CXP)') }}
					</NcActionButton>
					<NcActionButton :closeAfterClick="true" @click="openGdpr">
						{{ t('keepiq', 'GDPR export') }}
					</NcActionButton>
					<NcActionButton
						:closeAfterClick="true"
						@click="deletionOpen = true">
						{{ t('keepiq', 'Delete my Keepiq data') }}
					</NcActionButton>
				</template>

				<!-- Secret-type filter + sort (passkey-item-type §3.3) as a
				     funnel button BESIDE the search field: search + filters
				     group as "narrow what you see" on the bar's left (the
				     convention GitHub/Files-style list toolbars follow), while
				     the right cluster stays "display + act". Sorting lives in
				     the same menu instead of a standalone select — one compact
				     control for everything that reorders/narrows the list.
				     With a filter ACTIVE the funnel flips to its filled glyph
				     in the primary color, so an applied filter is always
				     visible even while the menu is closed. -->
				<template #after-search>
					<NcActions
						:forceMenu="true"
						:ariaLabel="t('keepiq', 'Filter and sort')"
						:class="{
							'secret-list-view__filter--active': filterMenuActive,
						}"
						data-testid="secret-type-filter-menu">
						<template #icon>
							<FilterIcon v-if="filterMenuActive" :size="20" />
							<FilterOutline v-else :size="20" />
						</template>
						<NcActionCaption :name="t('keepiq', 'Filter by type')" />
						<NcActionRadio
							name="secret-type-filter"
							value=""
							:modelValue="typeFilter ?? ''"
							data-testid="secret-type-filter"
							@update:modelValue="onTypeFilter(null)">
							{{ t('keepiq', 'All types') }}
						</NcActionRadio>
						<NcActionRadio
							v-for="option in typeFilterOptions"
							:key="option.value"
							name="secret-type-filter"
							:value="option.value"
							:modelValue="typeFilter ?? ''"
							data-testid="secret-type-filter"
							@update:modelValue="onTypeFilter(option.value)">
							{{ option.label }}
						</NcActionRadio>
						<NcActionSeparator />
						<NcActionCaption :name="t('keepiq', 'Sort by')" />
						<NcActionRadio
							v-for="option in sortOptions"
							:key="option.value"
							name="secret-sort"
							:value="option.value"
							:modelValue="sortField"
							data-testid="secret-sort-option"
							@update:modelValue="onSort(option.value)">
							{{ option.label }}
						</NcActionRadio>
					</NcActions>
				</template>
				<!-- Vault/folder strip (restyle Stage 6, revised): the current
				     folder's direct subfolders — the VAULTS at root — render as
				     a distinct section ABOVE the collection in every view mode,
				     so they never masquerade as secrets in the table or card
				     views. Clicking one navigates into it; folders are not
				     bulk-operable, so no checkbox. Filtered by the inline
				     search (team decision: everything visible is searchable),
				     independent of the secrets' pagination. -->
				<template #before-collection>
					<!-- Folder trail (restyle Stage 5): home crumb + parent
					     walk to the current folder (unlinked). BELOW the
					     actions bar — the bar always owns the top row — and
					     above the folder strip. Empty at the vault root, so
					     CnBreadcrumbs renders no landmark there. -->
					<div
						v-if="breadcrumbs.length > 0"
						class="secret-list-view__crumbs">
						<CnBreadcrumbs :crumbs="breadcrumbs" />
					</div>
					<!-- Only INSIDE a folder (2026-09-03, per Remko): the rows
					     are then the folder's own contents. At the root the
					     strip showed the vaults — navigation posing as
					     contents, duplicating the nav tree and pushing every
					     secret below a screen of vault rows. -->
					<div
						v-if="!folderSwitching && folderRows.length > 0"
						class="secret-list-view__folders">
						<!-- Group caption, Passwork-style: names the section
						     so folders read as a group, not as odd rows. -->
						<span class="secret-list-view__folders-caption">
							{{ t('keepiq', 'Folders') }}
						</span>
						<button
							v-for="folder in folderRows"
							:key="folder.id"
							type="button"
							class="secret-list-view__folder-row"
							:data-testid="`folder-row-${folder.folderId}`"
							@click="openFolder(folder.folderId)">
							<FolderOutline :size="20" />
							<span class="secret-list-view__folder-name">
								{{ folder.name }}
							</span>
						</button>
					</div>
				</template>

				<!-- Bulk actions (bulk-actions §3): the contextual selection
				     strip is THE surface — it appears the moment a selection
				     exists (Proton Pass / Gmail / Files pattern; §3.1's bulk
				     action bar) with a live count the library announces via
				     role="status" (WCAG 2.1 SC 4.1.3) and its own Clear
				     control. Selection is client-only (§1.2) and shared with
				     the library's checkboxes (table header select-all, card
				     and row checkboxes) via the bulk store. -->
				<!-- Disabled while the list is (re)loading: between "user
				     navigated away" and "the prune watcher saw the new rows"
				     the strip still shows the OLD view's selection — acting
				     on it would move/delete secrets from the previous page. -->
				<template #selection-actions>
					<NcButton
						variant="secondary"
						:disabled="loading || folderSwitching"
						data-testid="bulk-open-move"
						@click="bulkDialog = 'move'">
						<template #icon>
							<FolderMoveOutline :size="20" />
						</template>
						{{ t('keepiq', 'Move') }}
					</NcButton>
					<NcButton
						variant="secondary"
						:disabled="loading || folderSwitching"
						data-testid="bulk-open-share"
						@click="bulkDialog = 'share'">
						<template #icon>
							<ShareVariantOutline :size="20" />
						</template>
						{{ t('keepiq', 'Share') }}
					</NcButton>
					<NcButton
						variant="secondary"
						:disabled="loading || folderSwitching"
						data-testid="bulk-open-team-folder"
						@click="bulkDialog = 'teamFolder'">
						<template #icon>
							<AccountGroup :size="20" />
						</template>
						{{ t('keepiq', 'Add to team folder') }}
					</NcButton>
					<NcButton
						variant="error"
						:disabled="loading || folderSwitching"
						data-testid="bulk-open-delete"
						@click="bulkDialog = 'delete'">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('keepiq', 'Delete') }}
					</NcButton>
				</template>
				<!-- Rich empty state (restyle Stage 5). -->
				<template #empty>
					<NcEmptyContent
						:name="t('keepiq', 'No secrets found')"
						:description="
							t(
								'keepiq',
								'Add your first secret using the button above',
							)
						">
						<template #icon>
							<KeyVariant :size="64" />
						</template>
					</NcEmptyContent>
				</template>
				<template #list-item="{ object, selected }">
					<div class="secret-list-view__row">
						<!-- Per-row selection (bulk-actions §1.1): click
						     toggles, shift-click selects the range from the
						     last-clicked row in the current view. -->
						<!-- Nextcloud's own checkbox, same as the table and
						     card views. The capture-phase click listener only
						     records the shift state; update:modelValue does
						     the toggling exactly once (a raw click handler
						     would double-fire through the label's forwarded
						     click). -->
						<NcCheckboxRadioSwitch
							class="secret-list-view__check"
							:modelValue="bulkStore.selectedIds.includes(object.id)"
							:aria-label="
								t('keepiq', 'Select {name}', { name: object.name })
							"
							:data-testid="`bulk-check-${object.id}`"
							@click.capture="lastShiftKey = $event.shiftKey"
							@update:modelValue="onRowCheck(object)" />
						<SecretListItem
							class="secret-list-view__row-item"
							:class="{
								'secret-list-view__row-item--selected': selected,
							}"
							:secret="object"
							:requestState="requestStateFor(object)"
							:vault="rowVault(object)"
							@open="openSecret"
							@copied="onCopied" />
					</div>
				</template>
				<!-- Card view (fix-brief bugs 8+9): the SAME row component as
				     the list — favicon/type icon, strength pill, request/
				     blocked/compromised states, copy button — boxed as a card,
				     so the views cannot drift apart again. The #card slot
				     replaces CnObjectCard entirely, so the selection checkbox
				     is re-wired here exactly like the #list-item override. -->
				<template #card="{ object, selected }">
					<div
						class="secret-list-view__card"
						:class="{
							'secret-list-view__card--selected': selected,
						}">
						<NcCheckboxRadioSwitch
							class="secret-list-view__check"
							:modelValue="bulkStore.selectedIds.includes(object.id)"
							:aria-label="
								t('keepiq', 'Select {name}', { name: object.name })
							"
							:data-testid="`bulk-check-${object.id}`"
							@click.capture="lastShiftKey = $event.shiftKey"
							@update:modelValue="onRowCheck(object)" />
						<SecretListItem
							class="secret-list-view__card-item"
							:secret="object"
							:requestState="requestStateFor(object)"
							:vault="rowVault(object)"
							@open="openSecret"
							@copied="onCopied" />
					</div>
				</template>
				<!-- Table view (fix-brief bugs 8+9): the Type and Strength
				     columns declared in listSchema render through these cell
				     slots — their values live in the type/health stores, not
				     on the row object, so the generic cell renderer cannot
				     draw them. -->
				<!-- The Name cell carries the vault dot at the All-secrets
				     root — same rule as the rows: the table needs to say
				     where a secret lives without spending a whole column on
				     it, and the name is the thing the dot locates. rowVault
				     returns null inside a vault, hiding the dot. -->
				<template #column-name="{ row }">
					<span class="secret-list-view__name-cell">
						<span class="secret-list-view__name-cell-text">{{
							row.name
						}}</span>
						<VaultIndicator :vault="rowVault(row)" />
					</span>
				</template>
				<template #column-type="{ row }">
					<span class="secret-list-view__type-cell">
						<SecretTypeIcon :typeId="row.typeId" :size="20" />
						{{ typeLabelFor(row) }}
					</span>
				</template>
				<!-- Unscored rows (locked vault, machine key material,
				     blocked) get a muted dash instead of an empty cell:
				     locale-neutral, and honest where "N/A" would not be —
				     a locked vault means "not scored yet", not "not
				     applicable". Hidden from screen readers, which already
				     treat an empty cell as "no value". -->
				<template #column-strength="{ row }">
					<StrengthBadge
						v-if="strengthBadgeVisible(row)"
						:secretId="row.id" />
					<span
						v-else
						class="secret-list-view__strength-empty"
						aria-hidden="true"
						>—</span
					>
				</template>
			</CnIndexPage>
		</div>
		<!-- No `:secret` prop: the dialog creates the placeholder itself. -->
		<SecretRequestCreateDialog
			v-if="credentialRequestOpen"
			:open="credentialRequestOpen"
			data-testid="credential-request-dialog"
			@update:open="credentialRequestOpen = $event"
			@created="onCredentialRequested" />
	</div>
</template>

<script>
import { CnBreadcrumbs, CnIndexPage } from '@conduction/nextcloud-vue'
import {
	NcActionButton,
	NcActionCaption,
	NcActionCheckbox,
	NcActionRadio,
	NcActions,
	NcActionSeparator,
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
} from '@nextcloud/vue'
import { markRaw } from 'vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import FilterIcon from 'vue-material-design-icons/Filter.vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import FolderMoveOutline from 'vue-material-design-icons/FolderMoveOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import Import from 'vue-material-design-icons/Import.vue'
import KeyVariant from 'vue-material-design-icons/KeyVariant.vue'
import Safe from 'vue-material-design-icons/Safe.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import SecretListItem from '../components/SecretListItem.vue'
import SecretTypeIcon from '../components/SecretTypeIcon.vue'
import StrengthBadge from '../components/StrengthBadge.vue'
import VaultIndicator from '../components/VaultIndicator.vue'
import AccountDeletionDialog from '../dialogs/AccountDeletionDialog.vue'
import BulkDeleteDialog from '../dialogs/BulkDeleteDialog.vue'
import BulkMoveDialog from '../dialogs/BulkMoveDialog.vue'
import BulkShareDialog from '../dialogs/BulkShareDialog.vue'
import BulkTeamFolderDialog from '../dialogs/BulkTeamFolderDialog.vue'
import CxpTransferDialog from '../dialogs/CxpTransferDialog.vue'
import ExportDialog from '../dialogs/ExportDialog.vue'
import GdprExportDialog from '../dialogs/GdprExportDialog.vue'
import ImportWizardDialog from '../dialogs/ImportWizardDialog.vue'
import SecretRequestCreateDialog from '../dialogs/SecretRequestCreateDialog.vue'
import MySendsDialog from '../modals/MySendsDialog.vue'
import NewSendDialog from '../modals/NewSendDialog.vue'
import TeamFolderDialog from '../modals/TeamFolderDialog.vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useHealthStore } from '../store/modules/health.js'
import { useOfflineStore } from '../store/modules/offline.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretRequestStore } from '../store/modules/secretRequest.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useSessionStore } from '../store/modules/session.js'
import { secretDetailLocation } from '../utils/detailRoute.js'
import { secretTypeLabel } from '../utils/secretTypes.js'
import { rootVaultOf, subfolderRows } from '../utils/vaultList.js'

const PAGE_SIZE = 50

// Toolbar icon components, marked raw once so the declarative toolbarItems()
// entries never wrap component options in a reactive proxy.
const TOOLBAR_ICONS = {
	folderPlus: markRaw(FolderPlus),
	safe: markRaw(Safe),
	accountQuestion: markRaw(AccountQuestion),
	import: markRaw(Import),
	accountGroup: markRaw(AccountGroup),
}

/**
 * Hard ceiling on the breadcrumb parent walk. A well-formed tree never gets
 * near it; it exists so a corrupt parentId cycle that survives the seen-set
 * (e.g. duplicated ids) still terminates.
 */
const MAX_TRAIL_DEPTH = 32

/**
 * The main vault view: a folder sidebar plus a searchable, sortable,
 * paginated secret list, built on the shared CnIndexPage list view and
 * the app nav's folder tree. Names and urls are plaintext; encrypted fields are
 * decrypted only on demand (copy / detail) inside SecretListItem.
 */
export default {
	name: 'SecretList',

	components: {
		SecretRequestCreateDialog,
		CnBreadcrumbs,
		CnIndexPage,
		NcActionButton,
		NcActionCaption,
		NcActionCheckbox,
		NcActionRadio,
		NcActions,
		NcActionSeparator,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		AccountGroup,
		FilterIcon,
		FilterOutline,
		FolderMoveOutline,
		FolderOutline,
		ShareVariantOutline,
		TrashCanOutline,
		KeyVariant,
		SecretListItem,
		SecretTypeIcon,
		StrengthBadge,
		VaultIndicator,
		ExportDialog,
		CxpTransferDialog,
		GdprExportDialog,
		AccountDeletionDialog,
		ImportWizardDialog,
		TeamFolderDialog,
		BulkMoveDialog,
		BulkDeleteDialog,
		BulkShareDialog,
		BulkTeamFolderDialog,
		NewSendDialog,
		MySendsDialog,
	},

	inject: {
		/**
		 * Open a registry-registered modal. Provided by CnAppRoot; defaults to a
		 * no-op so the view still mounts in isolation (e.g. unit tests).
		 */
		cnOpenModal: { default: () => () => {} },
	},

	data() {
		return {
			credentialRequestOpen: false,
			searchTerm: '',
			sortField: 'name',
			searchTimer: null,
			exportOpen: false,
			cxpOpen: false,
			gdprOpen: false,
			deletionOpen: false,
			importOpen: false,
			teamFolderOpen: false,
			typeFilter: null,
			decryptedSecrets: [],
			bulkDialog: null,
			lastCheckedId: null,
			/**
			 * Shift state of the most recent checkbox click, recorded in the
			 * capture phase so onRowCheck (fired via update:modelValue, which
			 * carries no MouseEvent) can extend the selection as a range.
			 */
			lastShiftKey: false,
			newSendOpen: false,
			mySendsOpen: false,
			/**
			 * True while a folder navigation's fetch is in flight. Blanks
			 * the list so CnIndexPage shows its full loading spinner
			 * (it only does so when loading AND empty) instead of the
			 * PREVIOUS folder's rows suddenly swapping for the new ones.
			 *
			 * Starts TRUE: root ↔ folder navigations REMOUNT this view
			 * (CnPageRenderer keys renders on the page id, and the two
			 * routes are different pages), and a fresh mount renders the
			 * store's previous rows before mounted() fetches anything —
			 * the watcher below never sees that transition.
			 */
			folderSwitching: true,
		}
	},

	computed: {
		/**
		 * Secret id -> its pending request, for the row indicator.
		 *
		 * @return {object} Map of secretId to the pending request.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-outstanding-request-indicator
		 */
		pendingRequestsBySecret() {
			const map = {}
			for (const r of useSecretRequestStore().secretRequests || []) {
				if (r && r.status === 'pending' && r.secretId) {
					map[r.secretId] = r
				}
			}

			return map
		},

		secretStore() {
			return useSecretStore()
		},

		folderStore() {
			return useFolderStore()
		},

		secrets() {
			return this.secretStore.secrets
		},

		loading() {
			return this.secretStore.loading
		},

		/**
		 * The flat folder list, used to feed the folder sidebar, populate the
		 * export scope selector, and resolve relative folder paths in the
		 * export serializer.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		folders() {
			return this.folderStore.folders
		},

		/**
		 * Whether the vault is locked — the Import action is disabled while
		 * locked (import requires the session CryptoKey to encrypt rows).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 */
		vaultLocked() {
			return useSessionStore().isLocked
		},

		/**
		 * Whether the vault is being served from the offline cache and is
		 * therefore read-only (offline-readonly-cache §4.2). All write controls
		 * are disabled while true.
		 *
		 * @return {boolean}
		 */
		offlineReadOnly() {
			return useOfflineStore().readOnly
		},

		selectedFolderId() {
			return this.$route.params.folderId || null
		},

		/** Display name of the selected folder for the team-sharing dialog. */
		selectedFolderName() {
			const folder = this.folders.find((f) => f.id === this.selectedFolderId)
			return folder?.name ?? ''
		},

		pagination() {
			return {
				page: this.secretStore.page,
				pages: Math.max(
					1,
					Math.ceil(this.secretStore.totalCount / PAGE_SIZE),
				),

				total: this.secretStore.totalCount,
				limit: PAGE_SIZE,
			}
		},

		/**
		 * Schema for CnIndexPage's table view (cards render through the
		 * #card slot). Type and Strength (fix-brief bugs 8+9) are declared
		 * here so the table grows their columns; their cells render through
		 * the #column-type / #column-strength slots because the values live
		 * in the type/health stores, not on the row object. The order hints
		 * pin the column order — columnsFromSchema falls back to alphabetical.
		 *
		 * @return {object}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-secret-list-rows-must-be-keyboard-operable
		 */
		listSchema() {
			return {
				properties: {
					name: { title: t('keepiq', 'Name'), type: 'string', order: 1 },
					type: { title: t('keepiq', 'Type'), type: 'string', order: 2 },

					strength: {
						title: t('keepiq', 'Strength'),
						type: 'string',
						order: 3,
					},

					url: { title: t('keepiq', 'URL'), type: 'string', order: 4 },
				},

				configuration: {
					objectNameField: 'name',
					objectDescriptionField: 'url',
				},
			}
		},

		/**
		 * Type and Strength are synthesized columns — no row field backs
		 * them, and the server cannot sort on either — so their headers
		 * must not offer the sort affordance the schema defaults grant.
		 *
		 * @return {object}
		 * @spec exclude Presentation-only column configuration for the table view.
		 */
		listColumnOverrides() {
			return {
				type: { sortable: false },
				strength: { sortable: false },
			}
		},

		/**
		 * The sort fields the list offers, matching the columns the server
		 * accepts for the paged secrets query.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		sortOptions() {
			return [
				{ value: 'name', label: t('keepiq', 'Name') },
				{ value: 'url', label: t('keepiq', 'URL') },
				{ value: 'created_at', label: t('keepiq', 'Created') },
				{ value: 'updated_at', label: t('keepiq', 'Updated') },
			]
		},

		/**
		 * Whether the funnel menu holds a NON-DEFAULT state — a type filter,
		 * or a sort other than the default name sort. Drives the filled
		 * primary-colored funnel so a narrowed/reordered list is visible at
		 * a glance; the defaults (all types, sorted by name) keep the plain
		 * outline.
		 *
		 * @return {boolean}
		 * @spec exclude Presentation-only active-state derivation for the funnel button.
		 */
		filterMenuActive() {
			return !!this.typeFilter || this.sortField !== 'name'
		},

		/**
		 * Options for the secret-type filter (passkey-item-type §3.3).
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 */
		typeFilterOptions() {
			return useSecretTypeStore().types.map((type) => ({
				value: type.id,
				label: secretTypeLabel(type),
			}))
		},

		/**
		 * The current folder's direct subfolders as list rows (restyle
		 * Stage 6). Filtered by the inline search term — everything visible
		 * in the list is searchable — and name-sorted as one group.
		 *
		 * NONE at the root (2026-09-03, per Remko, Proton-style): "All
		 * secrets" is a cross-vault query, not a container the user is
		 * inside, so vault rows there were navigation posing as contents —
		 * duplicating the nav tree one panel away and pushing every secret
		 * below a screen of vaults. Inside a folder the rows stay: there
		 * they ARE the contents of the thing being looked at.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		folderRows() {
			if (!this.selectedFolderId) {
				return []
			}
			return subfolderRows(
				this.folders,
				this.selectedFolderId,
				this.searchTerm,
			)
		},

		/**
		 * What the collection renders: SECRETS only. The subfolder/vault
		 * rows moved to a dedicated strip above the collection (the
		 * `#before-collection` slot), so folders never masquerade as
		 * secrets in the table and card views and are independent of
		 * pagination. Pagination totals keep counting secrets only.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		listObjects() {
			// Mid folder-switch: nothing — the old folder's rows must not
			// linger while the new folder's secrets are still loading.
			if (this.folderSwitching) {
				return []
			}
			return this.secrets
		},

		/**
		 * The page title: the selected folder's name, or "Secrets" at the
		 * vault root (restyle Stage 5).
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		pageTitle() {
			return this.selectedFolderName || t('keepiq', 'Secrets')
		},

		/**
		 * Level-appropriate create label (restyle terminology): a TOP-LEVEL
		 * folder is a Vault, a folder inside one is a folder.
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		newFolderLabel() {
			return this.selectedFolderId
				? t('keepiq', 'New folder')
				: t('keepiq', 'New vault')
		},

		/**
		 * The selected folder's ancestor chain, root first, current folder
		 * last — walked through `parentId` (the field buildTree links on in
		 * the folder store). Guarded against parentId cycles (seen-set) and
		 * runaway depth.
		 *
		 * @return {Array<object>} Folder objects along the trail.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		folderTrail() {
			if (!this.selectedFolderId) {
				return []
			}
			const byId = new Map(this.folders.map((f) => [f.id, f]))
			const trail = []
			const seen = new Set()
			let current = byId.get(this.selectedFolderId)
			while (
				current
				&& !seen.has(current.id)
				&& trail.length < MAX_TRAIL_DEPTH
			) {
				seen.add(current.id)
				trail.unshift(current)
				current = current.parentId ? byId.get(current.parentId) : null
			}
			return trail
		},

		/**
		 * The breadcrumb trail (restyle Stage 5): a Home crumb back to the
		 * vault root, the ancestor folders as links, the current folder
		 * last (CnBreadcrumbs renders it unlinked with aria-current). Empty
		 * at the root — no trail is rendered there.
		 *
		 * @return {Array<object>} CnBreadcrumbs `crumbs` entries.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		breadcrumbs() {
			const trail = this.folderTrail
			if (trail.length === 0) {
				return []
			}
			return [
				{ icon: 'Home', to: { name: 'SecretList' } },
				...trail.map((folder, index) =>
					index === trail.length - 1
						? { label: folder.name }
						: {
								label: folder.name,
								to: {
									name: 'SecretListFolder',
									params: { folderId: folder.id },
								},
							},
				),
			]
		},

		/**
		 * The declarative page actions (restyle Stages 5 + 8). All entries
		 * render inside the actions bar's overflow menu (the `#action-items`
		 * slot); ids, testids and disabled conditions are stable. "New
		 * secret" is not listed — it stays CnIndexPage's own add button —
		 * and Refresh is not listed — the actions bar carries its own
		 * built-in Refresh entry, wired via `@refresh`/`:refreshing`.
		 *
		 * @return {Array<{id: string, label: string, icon: object, disabled: boolean, testid: (string|undefined), run: Function}>}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		toolbarItems() {
			const items = [
				{
					id: 'new-folder',
					label: this.newFolderLabel,
					// A top-level create makes a VAULT, so the entry carries
					// the safe glyph there (restyle terminology).
					icon: this.selectedFolderId
						? TOOLBAR_ICONS.folderPlus
						: TOOLBAR_ICONS.safe,

					disabled: this.offlineReadOnly,
					testid: 'open-create-folder',
					run: () => this.openCreateFolder(),
				},
				// Ask someone for a credential (request-first-secret-requests):
				// reachable with an EMPTY vault, because a requester has nothing
				// to point at yet. The Secret is created by the request itself,
				// so this is not "make a secret then request into it".
				{
					id: 'credential-request',
					label: t('keepiq', 'Ask for a credential'),
					icon: TOOLBAR_ICONS.accountQuestion,
					disabled: this.vaultLocked || this.offlineReadOnly,
					testid: 'open-credential-request',
					run: () => {
						this.credentialRequestOpen = true
					},
				},
				{
					id: 'import',
					label: t('keepiq', 'Import'),
					icon: TOOLBAR_ICONS.import,
					disabled: this.vaultLocked || this.offlineReadOnly,
					testid: 'import-secrets',
					run: () => this.openImport(),
				},
			]
			// Team folder sharing (team-folder-sharing §5.1): only for a
			// concrete selected folder; the dialog owns membership + fan-out.
			if (this.selectedFolderId) {
				items.push({
					id: 'team-sharing',
					label: t('keepiq', 'Team sharing'),
					icon: TOOLBAR_ICONS.accountGroup,
					disabled: this.vaultLocked,
					testid: 'team-folder-open',
					run: () => {
						this.teamFolderOpen = true
					},
				})
			}
			return items
		},

		/** Bulk selection store (bulk-actions §1). */
		bulkStore() {
			return useBulkStore()
		},

		/** Whether every secret in the current view is selected. */
		allCurrentSelected() {
			return (
				this.secrets.length > 0
				&& this.secrets.every((s) =>
					this.bulkStore.selectedIds.includes(s.id),
				)
			)
		},
	},

	watch: {
		/**
		 * Reload the list when the route's folder changes.
		 *
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		async selectedFolderId() {
			// Blank the list for the duration of the fetch so the switch
			// reads as loading → new folder, never old rows → new rows.
			this.folderSwitching = true
			try {
				await this.reload()
			} finally {
				this.folderSwitching = false
			}
		},

		/**
		 * Prune the selection to the rows actually VISIBLE whenever the
		 * list changes (folder navigation, page flip, filter, refresh):
		 * selection is scoped to the current view (§1.1 — select-all is
		 * too), so ids that left the view must not linger invisibly in the
		 * bulk store and keep the selection strip alive over an unrelated
		 * folder.
		 *
		 * @param {Array<object>} newSecrets The freshly loaded secrets.
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-multi-select-and-bulk-action-bar
		 */
		secrets(newSecrets) {
			const visible = new Set((newSecrets || []).map((s) => s.id))
			const pruned = this.bulkStore.selectedIds.filter((id) => visible.has(id))
			if (pruned.length !== this.bulkStore.selectedIds.length) {
				this.bulkStore.setSelection(pruned)
			}
		},

		/**
		 * Dashboard quick-action deep link (`/secrets?action=create`): open
		 * the create-secret dialog. A watcher rather than a mounted() check,
		 * because CnPageRenderer keeps the list mounted when only the query
		 * changes (its render key is the page id), so a later navigation to
		 * the same page with the marker must still be seen.
		 *
		 * @param {string|undefined} action The `action` query value.
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		'$route.query.action': {
			immediate: true,
			/**
			 * Dispatch the marker to the consumer; anything else is ignored.
			 *
			 * @param {string|undefined} action The `action` query value.
			 * @return {void}
			 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
			 */
			handler(action) {
				if (action === 'create') {
					this.consumeCreateAction()
				}
			},
		},
	},

	/**
	 * Load types + folders + the first secrets page, then lazily run the
	 * client-side password-health pass so strength badges appear.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
	 */
	async mounted() {
		// The bulk selection is client-only and dies with the lock (§1.2).
		this.bulkStore.registerLockReset()
		// allSettled (not all): offline, the type/folder fetches fall back to
		// the cache but must never block the secret-list load if one rejects
		// (offline-readonly-cache §4.2).
		await Promise.allSettled([
			useSecretTypeStore().fetchTypes(),
			this.folderStore.fetchFolders(),
			// Drives the outstanding-request badge. allSettled, so a failure here
			// costs the badge and never the list itself.
			useSecretRequestStore().fetchRequests(),
		])
		try {
			await this.reload()
		} finally {
			// Mount-time counterpart of the selectedFolderId watcher:
			// `folderSwitching` starts true so the spinner covers this whole
			// preamble instead of the store's previous rows.
			this.folderSwitching = false
		}
		// Lazily run the client-side password-health pass after the first list
		// render so strength badges appear without blocking the render. Fire and
		// forget; it aborts cleanly when the vault is locked (password-health D2).
		this.triggerHealthPass()
	},

	methods: {
		t,

		/**
		 * Per-row selection toggle with shift-click range support
		 * (bulk-actions §1.1): shift extends from the last-clicked row
		 * to this one within the current view's order. The shift state
		 * comes from `lastShiftKey`, recorded by the checkbox's
		 * capture-phase click listener just before the component emits.
		 *
		 * @param {object} object The clicked row's secret.
		 * @return {void}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-multi-select-and-bulk-action-bar
		 */
		onRowCheck(object) {
			const ids = new Set(this.bulkStore.selectedIds)
			if (this.lastShiftKey && this.lastCheckedId) {
				const order = this.secrets.map((s) => s.id)
				const from = order.indexOf(this.lastCheckedId)
				const to = order.indexOf(object.id)
				if (from !== -1 && to !== -1) {
					for (const id of order.slice(
						Math.min(from, to),
						Math.max(from, to) + 1,
					)) {
						ids.add(id)
					}
					this.bulkStore.setSelection([...ids])
					this.lastCheckedId = object.id
					return
				}
			}

			if (ids.has(object.id)) {
				ids.delete(object.id)
			} else {
				ids.add(object.id)
			}
			this.bulkStore.setSelection([...ids])
			this.lastCheckedId = object.id
		},

		/**
		 * Select-all / clear-all for the current filtered view (§1.1).
		 *
		 * @param {boolean} checked The NcActionCheckbox model value.
		 * @return {void}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-multi-select-and-bulk-action-bar
		 */
		onSelectAll(checked) {
			const ids = new Set(this.bulkStore.selectedIds)
			for (const secret of this.secrets) {
				if (checked) {
					ids.add(secret.id)
				} else {
					ids.delete(secret.id)
				}
			}
			this.bulkStore.setSelection([...ids])
		},

		/** Close whichever bulk dialog is open. */
		closeBulkDialog() {
			this.bulkDialog = null
		},

		/**
		 * A bulk run finished: refresh the list so moved/deleted rows
		 * reflect reality; keep the dialog open to show the report.
		 *
		 * @return {Promise<void>}
		 */
		async onBulkDone() {
			await this.reload()
		},

		/**
		 * Decrypt every secret the user can read, in the browser, so the export
		 * dialogs can serialize the full vault. Returns [] when the vault is
		 * locked (the dialogs then fall back to metadata-only where applicable).
		 *
		 * @return {Promise<Array<object>>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		async decryptAllSecrets() {
			const store = this.secretStore
			// Pull the WHOLE vault (the export covers everything, not just the
			// paginated view); paged within the server's per-request cap.
			await store.fetchAllSecrets()
			const out = []
			for (const secret of store.secrets) {
				try {
					out.push(await store.decryptSecret(secret))
				} catch {
					// A secret whose suite is blocked/revoked cannot be decrypted;
					// skip it rather than failing the whole export.
				}
			}
			return out
		},

		/**
		 * Open the export dialog after decrypting the vault client-side.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		async openExport() {
			this.decryptedSecrets = await this.decryptAllSecrets()
			this.exportOpen = true
		},

		/**
		 * Open the CXP encrypted-transfer dialog; decrypt the vault so the send
		 * direction can assemble + seal the CXF payload client-side.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/cxp-transfer/specs/cxp-transfer/spec.md
		 */
		async openCxp() {
			this.decryptedSecrets = await this.decryptAllSecrets()
			this.cxpOpen = true
		},

		/**
		 * Open the GDPR export dialog; decrypt the vault if it is unlocked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		async openGdpr() {
			this.decryptedSecrets = await this.decryptAllSecrets()
			this.gdprOpen = true
		},

		/**
		 * Open the import wizard. Guarded against a locked vault — import needs
		 * the session CryptoKey to encrypt rows client-side. When locked, the
		 * wizard is not opened and reads no file; the toolbar button is also
		 * disabled while locked, and the wizard itself renders a lock guard.
		 *
		 * @return {void}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 */
		/**
		 * The outstanding-request state for a row, or null.
		 *
		 * Distinguishes a Secret that cannot be used yet from one that works until
		 * new values arrive — the consequences differ, so one badge for both would
		 * be worse than none. Returns null once the request is no longer pending,
		 * which is what clears the badge on fulfilment, revocation or expiry.
		 *
		 * @param {object} secret The row's secret.
		 *
		 * @return {string|null} 'awaiting-fill', 're-request' or null.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-outstanding-request-indicator
		 */
		requestStateFor(secret) {
			if (!secret || !this.pendingRequestsBySecret[secret.id]) {
				return null
			}

			return String(secret.key || '') === '' ? 'awaiting-fill' : 're-request'
		},

		/**
		 * The translated type label for a row's Type cell (fix-brief bugs
		 * 8+9), resolved against the type catalogue; empty while the
		 * catalogue has not loaded the row's type yet.
		 *
		 * @param {object} secret The row's secret.
		 * @return {string} The label, or '' when the type is unknown.
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 */
		typeLabelFor(secret) {
			const type = useSecretTypeStore().typesById[secret.typeId]
			return type ? secretTypeLabel(type) : ''
		},

		/**
		 * The vault a row's dot indicator names — ONLY at the All-secrets
		 * root (2026-09-03, per Remko, Proton pattern): a cross-vault list
		 * is the one place a row must say where its secret lives. Inside a
		 * vault the page itself is the location, so no dot renders there.
		 *
		 * @param {object} secret The row's secret.
		 * @return {object|null} The root vault record, or null for no dot.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		rowVault(secret) {
			if (this.selectedFolderId) {
				return null
			}
			return rootVaultOf(this.folders, secret.folderId)
		},

		/**
		 * Whether the table's Strength cell shows the badge: a scored,
		 * unblocked row. Blocked rows are guarded here rather than trusting
		 * the score map — a suite revoked mid-session can leave a stale
		 * score behind for a row that can no longer be decrypted, and the
		 * list view suppresses the badge on blocked rows for the same
		 * reason. Everything else renders the placeholder dash.
		 *
		 * @param {object} secret The row's secret.
		 * @return {boolean} True when StrengthBadge would render a pill.
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
		 */
		strengthBadgeVisible(secret) {
			if (secret.blocked) {
				return false
			}
			const score = useHealthStore().scoreById[secret.id]
			return score !== undefined && score !== null
		},

		/**
		 * A credential was requested from the vault level.
		 *
		 * The request created its own placeholder Secret, so the list must refetch
		 * to show it — otherwise the row only appears after a manual reload and
		 * the user cannot see what they just asked for.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-outstanding-request-indicator
		 */
		async onCredentialRequested() {
			// Deliberately NOT closing: the dialog has just computed the fill link
			// and is showing it with a Copy button and its own Done action. Closing
			// here unmounts it one tick later and destroys the only copy of the URL
			// the requester will ever be offered.
			// Both: the placeholder Secret is new, and its request drives the badge.
			await Promise.all([
				this.reload(),
				useSecretRequestStore().fetchRequests(),
			])
		},

		openImport() {
			if (this.vaultLocked) {
				return
			}
			this.importOpen = true
		},

		/**
		 * After an import completes, reload the secret list + folder tree so the
		 * imported secrets and any created folders appear.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-import-summary-report
		 */
		async onImported() {
			await this.folderStore.fetchFolders()
			await this.reload()
		},

		/**
		 * "Export first" suggestion from the deletion dialog: close it and open
		 * the export dialog.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		async onExportFirst() {
			this.deletionOpen = false
			await this.openExport()
		},

		/**
		 * Lazily run the password-health analysis so the list shows strength
		 * badges. Memory-only; aborts when the vault is locked.
		 *
		 * @return {void}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
		 */
		triggerHealthPass() {
			const health = useHealthStore()
			health.registerLockReset()
			if (health.status === 'idle') {
				health
					.analyseVault({
						stalenessThreshold: '365',
						breachEnabled: false,
					})
					.catch(() => {})
			}
		},

		/**
		 * Reload the current page of secrets with the active filters.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		async reload() {
			// Declare the query to the store, don't just pass it: the detail
			// sidebar and the folder dialogs refresh this list with a bare
			// `fetchSecrets()` and have no way to know what it is showing. While
			// the query lived only in these call arguments, every such refresh
			// dropped the folder filter and replaced a folder's contents with
			// the whole vault.
			this.secretStore.setListQuery({
				folderId: this.selectedFolderId,
				search: this.searchTerm,
				sort: this.sortField,
				typeId: this.typeFilter,
			})
			await this.secretStore.fetchSecrets({ page: 1 })
		},

		/**
		 * Type-filter change handler (passkey-item-type §3.3).
		 *
		 * @param {string|null} typeId The selected type id (null = all).
		 * @return {void}
		 */
		onTypeFilter(typeId) {
			this.typeFilter = typeId
			this.reload()
		},

		/**
		 * Debounced inline-search handler.
		 *
		 * @param {string} value The current search value.
		 * @return {void}
		 */
		onSearch(value) {
			this.searchTerm = value
			if (this.searchTimer) {
				clearTimeout(this.searchTimer)
			}
			this.searchTimer = setTimeout(() => {
				this.secretStore.searchSecrets(this.searchTerm)
			}, 300)
		},

		/**
		 * Change the sort field and reload.
		 *
		 * @param {string} value The chosen sort field.
		 * @return {void}
		 */
		onSort(value) {
			this.sortField = value
			this.reload()
		},

		/**
		 * Go to a specific page.
		 *
		 * @param {number} target The target page number.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		goToPage(target) {
			// Same contract as reload(): the store holds the query, this call
			// only moves the page. Note this used to omit `typeId`, so paging a
			// type-filtered list silently un-filtered it.
			this.secretStore.setListQuery({
				folderId: this.selectedFolderId,
				search: this.searchTerm,
				sort: this.sortField,
				typeId: this.typeFilter,
			})
			this.secretStore.fetchSecrets({ page: target })
		},

		/**
		 * Open a secret's detail sidebar (restyle Stage 8): the optional
		 * `:id?` segment joins the current list route, so the list stays
		 * mounted behind the sidebar and the folder context survives in
		 * the path.
		 *
		 * @param {string} id The secret ID.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		openSecret(id) {
			this.$router.push(secretDetailLocation(this.$route, id))
		},

		/**
		 * Selection change from the library's checkboxes (table header
		 * select-all, table/card row checkboxes) into the shared bulk
		 * store — the collection holds secrets only (folders live in the
		 * strip above it), so the ids pass through unfiltered.
		 *
		 * @param {Array<string>} ids The selected row ids from CnIndexPage.
		 * @return {void}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-multi-select-and-bulk-action-bar
		 */
		onLibrarySelect(ids) {
			this.bulkStore.setSelection(ids || [])
		},

		/**
		 * Row/card click in the table and card views (`rowClickToView`:
		 * clicking opens the detail sidebar, the checkbox selects — same
		 * split as the list view).
		 *
		 * @param {object} row The clicked row object.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		onRowOpen(row) {
			if (!row) return
			this.openSecret(row.id)
		},

		/**
		 * Navigate into a subfolder row (restyle Stage 6).
		 *
		 * @param {string} folderId The clicked folder's id.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		openFolder(folderId) {
			this.$router.push(`/folders/${folderId}`)
		},

		/**
		 * Copied-toast hook (SecretListItem @copied). No-op placeholder for
		 * future toast wiring; kept so the event has a handler.
		 *
		 * @return {void}
		 */
		onCopied() {},

		/**
		 * Open the create-secret dialog, defaulting the folder to the current
		 * view, and reload the list on success.
		 *
		 * @return {void}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		openCreateSecret() {
			this.cnOpenModal('secret-create', {
				folderId: this.selectedFolderId,
				onSaved: () => this.reload(),
			})
		},

		/**
		 * Consume the dashboard tile's `?action=create` marker: strip it from
		 * the URL, then open the create-secret dialog for the current folder.
		 *
		 * Stripping matters because a full page load re-locks the vault and
		 * the lock screen's `returnUrl` carries the query along — without it,
		 * every unlock after a refresh would re-open the dialog.
		 *
		 * STRICTLY strip-first: CnAppRoot closes the active registry modal on
		 * EVERY route change (so dialogs cannot follow a navigation to another
		 * page), and the query replace IS a route change — opening first got
		 * the dialog closed in the same tick it opened. The nextTick lets that
		 * route watcher run before the modal opens.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		async consumeCreateAction() {
			const query = { ...this.$route.query }
			delete query.action
			await this.$router.replace({ query })
			await this.$nextTick()
			this.openCreateSecret()
		},

		/**
		 * Open the create-folder dialog and reload the folder tree on success.
		 *
		 * @param {{ parentId: (string|null) }} [payload] The parent folder id.
		 * @return {void}
		 */
		openCreateFolder({ parentId } = {}) {
			this.cnOpenModal('folder-create', {
				parentId: parentId ?? this.selectedFolderId,
				onSaved: () => this.folderStore.fetchFolders(),
			})
		},
	},
}
</script>

<style scoped>
/* No own padding: CnIndexPage already pads the page (5 × baseline); the
   old extra 16px doubled every edge and, with the page title visually
   hidden (Stage 8), left a dead band between the nav toggle and the bar. */
.secret-list-view {
	height: 100%;
}

.secret-list-view__main {
	min-width: 0;
}

/* With the title row gone (keepiq-only — other apps keep their heading
   beside the toggle), the actions bar moves UP into that row: trim the
   page's top padding and clear the floating nav toggle. A MARGIN, not
   padding: the bar's background must START after the toggle (padding
   kept the background underneath it) — and since only the toggle's
   protruding half overlaps the content area, ~30px clears it (56px, the
   in-flow clearance CnPageHeader uses, left a hole). The !important
   guards against design-system themes that flatten the bar's box with
   their own !important rules. */
.secret-list-view :deep(.cn-index-page) {
	padding-top: 4px;
}

.secret-list-view :deep(.cn-actions-bar) {
	margin-inline-start: 30px !important;

	/* Container-scale rounding, keepiq-only: with the bar as the page's TOP
	   element its shell reads flat at the small element radius next to the
	   pill-shaped search and view toggle inside. Other apps keep the
	   library default — their bars sit mid-page under a title, and that
	   rounding call is theirs. Fallbacks for older server generations. */
	border-radius: var(
		--border-radius-container-large,
		var(--border-radius-large, 12px)
	);
}

/* The selection strip nests one step tighter than the bar's shell. */
.secret-list-view :deep(.cn-actions-bar__selection) {
	border-radius: var(--border-radius-container, var(--border-radius-large, 8px));
}

/* Breadcrumb trail: below the bar, above the folder strip. With the page
   title hidden, the trail IS the folder heading (Passwork-style), so it
   renders a step larger — via the font token, which NcBreadcrumb's
   internals consume. */
.secret-list-view__crumbs {
	--default-font-size: 16px;
	margin-bottom: 8px;
}

/* Passwork-style group caption over the folder strip. */
.secret-list-view__folders-caption {
	padding: 4px 12px;
	font-size: 12px;
	font-weight: 600;
	letter-spacing: 0.05em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

/* Vault/folder strip: a distinct section above the collection in every
   view mode, so folders never read as secrets among the rows or cards.
   The strip owns the divider (it renders in EVERY view mode); the list
   view's own container top-border is suppressed below so the two never
   stack into an empty band. */
.secret-list-view__folders {
	display: flex;
	flex-direction: column;
	margin-bottom: 8px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.secret-list-view :deep(.cn-object-list__rows) {
	border-top: none;
}

/* No selected-row tint: the checked checkbox (plus the selection strip's
   count) is the signal — the primary wash over whole rows read as noise
   next to it (review call). */
.secret-list-view__row {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* Subfolder rows (restyle Stage 6): full-width, file-manager style. */
.secret-list-view__folder-row {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 10px 12px;
	border: none;
	border-radius: var(--border-radius-large, 12px);
	background: transparent;
	cursor: pointer;
	text-align: start;
	font: inherit;
	color: inherit;
}

.secret-list-view__folder-row:hover,
.secret-list-view__folder-row:focus-visible {
	background-color: var(--color-background-hover, #f5f5f5);
}

.secret-list-view__folder-name {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: 600;
}

.secret-list-view__check {
	flex: 0 0 auto;
}

.secret-list-view__row-item {
	flex: 1 1 auto;
	min-width: 0;
}

/* Selected rows tint the ITEM only (same token as its hover state), never
   the checkbox gutter — the earlier full-row primary wash read as noise
   (review call). */
.secret-list-view__row-item--selected {
	background-color: var(--color-background-hover);
}

/* Card view (fix-brief bugs 8+9): the list-row component boxed as a card.
   Shell tokens mirror CnObjectCard, so these cards sit indistinguishable
   next to library-rendered ones elsewhere in the fleet. */
.secret-list-view__card {
	display: flex;
	/* Centered like the row content beside it — a top-pinned checkbox next
	   to vertically centered text read as misaligned (review call). */
	align-items: center;
	gap: 4px;
	height: 100%;
	padding: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	transition:
		box-shadow 0.2s ease,
		border-color 0.2s ease;
}

.secret-list-view__card:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 2px 8px var(--color-box-shadow);
}

/* Selection marks the card's BORDER, like CnObjectCard — the row-item's
   background tint is the list view's signal and reads as hover here. */
.secret-list-view__card--selected {
	border-color: var(--color-primary-element);
}

.secret-list-view__card-item {
	flex: 1 1 auto;
	min-width: 0;
	border-bottom: none;
	border-radius: var(--border-radius);
}

/* Table Name cell: name + (root only) the vault dot as one centered unit;
   the name keeps its ellipsis so the dot never pushes the cell wider. */
.secret-list-view__name-cell {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	max-width: 100%;
}

.secret-list-view__name-cell-text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Table Type cell: glyph + label, vertically centered as one unit. */
.secret-list-view__type-cell {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

/* The Strength cell's "no score" dash: present so the column never shows
   blank holes between pills, muted so it never competes with them. */
.secret-list-view__strength-empty {
	color: var(--color-text-maxcontrast);
}

@media (prefers-reduced-motion: reduce) {
	.secret-list-view__card {
		transition: none;
	}
}

/* Active type filter: the funnel already flips to its filled glyph; the
   primary color makes the applied-filter state readable at a glance even
   in a row of monochrome controls. */
.secret-list-view__filter--active :deep(button) {
	color: var(--color-primary-element);
}
</style>
