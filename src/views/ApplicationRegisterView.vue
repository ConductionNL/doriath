<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Developer-facing "register an application" page. Shows the user's own
  application registrations (on the shared CnIndexPage list view) and lets
  them add new ones via the ApplicationRegisterDialog. When the server returns
  a one-time private key the PrivateKeyDownloadDialog is shown until the user
  acknowledges.

  Admins should use `AdminApplicationsView` for the approval queue;
  this view is intentionally non-admin scoped.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.1
-->
<template>
	<div
		class="keepiq-application-register-view"
		data-testid="application-register-view">
		<CnIndexPage
			viewMode="list"
			:availableViewModes="['list', 'cards', 'table']"
			listLabel="List"
			:selectable="false"
			:objects="rows"
			:schema="listSchema"
			:listConfig="listConfig"
			:loading="store.loading"
			:addLabel="t('keepiq', 'Register application')"
			addIcon="Plus"
			inlineSearch
			:searchValue="searchTerm"
			:searchPlaceholder="t('keepiq', 'Search applications')"
			rowKey="id"
			:emptyText="t('keepiq', 'You have not registered any applications yet.')"
			@add="dialogOpen = true"
			@search="onSearch"
			@rowClick="openApplication">
			<template #row-badges="{ object }">
				<CnStatusBadge
					:label="statusLabel(object.status)"
					:variant="statusVariant(object.status)"
					size="small" />
				<CnStatusBadge
					v-if="object.type"
					:label="object.type"
					variant="default"
					size="small" />
			</template>
		</CnIndexPage>

		<ApplicationRegisterDialog
			:open="dialogOpen"
			@close="dialogOpen = false"
			@registered="onRegistered" />

		<PrivateKeyDownloadDialog
			:open="
				store.oneTimePrivateKey !== null && store.oneTimePrivateKey !== ''
			"
			:privateKey="store.oneTimePrivateKey || ''"
			@close="onAcknowledgeKey" />
	</div>
</template>

<script>
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import ApplicationRegisterDialog from '../dialogs/ApplicationRegisterDialog.vue'
import PrivateKeyDownloadDialog from '../dialogs/PrivateKeyDownloadDialog.vue'
import { useApplicationStore } from '../store/modules/application.js'

export default {
	name: 'ApplicationRegisterView',

	components: {
		CnIndexPage,
		CnStatusBadge,
		ApplicationRegisterDialog,
		PrivateKeyDownloadDialog,
	},

	data() {
		return {
			dialogOpen: false,
			searchTerm: '',
			store: useApplicationStore(),
		}
	},

	computed: {
		rows() {
			const term = this.searchTerm.trim().toLowerCase()
			const all = this.store.applications || []
			if (!term) return all
			return all.filter((a) => (a.name || '').toLowerCase().includes(term))
		},

		/**
		 * The CnList schema that renders the registered-application rows.
		 *
		 * @return {object}
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-register-application
		 */
		listSchema() {
			return {
				properties: {
					name: { title: t('keepiq', 'Name'), type: 'string' },
					description: {
						title: t('keepiq', 'Description'),
						type: 'string',
					},
				},

				configuration: {
					objectNameField: 'name',
					objectDescriptionField: 'description',
				},
			}
		},

		listConfig() {
			return { titleField: 'name', subtitleField: 'description' }
		},
	},

	watch: {
		/**
		 * Dashboard quick-action deep link (`/applications?action=register`):
		 * open the register dialog and strip the marker from the URL, so a
		 * refresh (which re-locks the vault and round-trips the query through
		 * the lock screen's `returnUrl`) does not re-open the dialog. A
		 * watcher rather than a mounted() check because CnPageRenderer keeps
		 * the view mounted when only the query changes.
		 *
		 * @param {string|undefined} action The `action` query value.
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-register-application
		 */
		'$route.query.action': {
			immediate: true,
			/**
			 * Open the dialog and strip the marker; anything else is ignored.
			 * The dialog is local view state (not a registry modal), so the
			 * query replace cannot close it the way it would a cnOpenModal
			 * dialog.
			 *
			 * @param {string|undefined} action The `action` query value.
			 * @return {void}
			 * @spec openspec/specs/application-mgmt/spec.md#requirement-register-application
			 */
			handler(action) {
				if (action === 'register') {
					this.dialogOpen = true
					const query = { ...this.$route.query }
					delete query.action
					this.$router.replace({ query })
				}
			},
		},
	},

	mounted() {
		this.store.fetchApplications().catch(() => {})
	},

	methods: {
		t,

		onSearch(value) {
			this.searchTerm = value
		},

		openApplication(object) {
			this.$router.push(`/applications/${object.id}`)
		},

		/**
		 * The translated registration status shown on a list row.
		 *
		 * @param {string} status The stored application status.
		 * @return {string}
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-approval-queue
		 */
		statusLabel(status) {
			switch (status) {
				case 'active':
					return t('keepiq', 'Active')
				case 'pending':
					return t('keepiq', 'Pending approval')
				case 'rejected':
					return t('keepiq', 'Rejected')
				default:
					return status || ''
			}
		},

		statusVariant(status) {
			switch (status) {
				case 'active':
					return 'success'
				case 'pending':
					return 'warning'
				case 'rejected':
					return 'error'
				default:
					return 'default'
			}
		},

		onRegistered() {
			this.dialogOpen = false
			// Refresh the list so the new row shows up.
			this.store.fetchApplications().catch(() => {})
		},

		onAcknowledgeKey() {
			this.store.clearOneTimePrivateKey()
		},
	},
}
</script>

<style scoped>
.keepiq-application-register-view {
	padding: 16px;
	height: 100%;
	overflow: auto;
}
</style>
