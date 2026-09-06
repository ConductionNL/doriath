/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * V2 component registry for keepiq (hydra ADR-036).
 *
 * Recognised kinds: page, widget, modal, form-field, cell-renderer.
 *
 * Page resolution path at runtime:
 *   - `pages[].type === 'custom'` → CnPageRenderer resolves
 *     `page.component` against `customComponents` (derived from the
 *     `kind:"page"` entries of this registry in src/main.js).
 *   - Dashboard `widgets[]` entries whose `widgetKey` is not built-in
 *     are looked up against this registry via `CnWidgetGrid` (the
 *     `kind:"widget"` entries below).
 *
 * ZERO custom `kind:"widget"` entries (hydra ADR-049, Decision 5) —
 * the dashboard's banners, KPI tiles, and placeholder panels are
 * built-in manifest widgets (`banner`, `stat`, `text`) configured in
 * src/manifest.json against the summary / migration-status endpoints.
 *
 * Notable entries:
 *   - LockScreen (page) — master-password setup + unlock flow.
 *     `type:"custom"` because the lock owns its own full-page layout
 *     (no app nav / header / sidebar) and the existing lock-redirect
 *     watcher in App.vue must short-circuit normal navigation. No lib
 *     primitive matches yet.
 *
 * @type {Record<string, { kind: string, component: object, defaultSize?: object, minSize?: object, maxSize?: object, allowedSlots?: string[], propsSchema?: object }>}
 */

import SecretRequestForm from './components/secretRequest/SecretRequestForm.vue'
import SecretRequestList from './components/secretRequest/SecretRequestList.vue'
import GroupShareForm from './components/share/GroupShareForm.vue'
import ShareDialog from './components/share/ShareDialog.vue'
import ShareList from './components/share/ShareList.vue'
import ApplicationRegisterDialog from './dialogs/ApplicationRegisterDialog.vue'
import FolderCreateDialog from './dialogs/FolderCreateDialog.vue'
import MoveDialog from './dialogs/MoveDialog.vue'
import PrivateKeyDownloadDialog from './dialogs/PrivateKeyDownloadDialog.vue'
import SecretCreateDialog from './dialogs/SecretCreateDialog.vue'
import SecretDeleteConfirmDialog from './dialogs/SecretDeleteConfirmDialog.vue'
import SecretEditDialog from './dialogs/SecretEditDialog.vue'
import SecretShareDialog from './dialogs/SecretShareDialog.vue'
import ApplicationDetail from './views/ApplicationDetail.vue'
import ApplicationRegisterView from './views/ApplicationRegisterView.vue'
import CertificateInventoryView from './views/CertificateInventoryView.vue'
import EmergencyAccessView from './views/EmergencyAccessView.vue'
import EphemeralSendAccess from './views/EphemeralSendAccess.vue'
import FlowDetailSidebar from './views/flows/FlowDetailSidebar.vue'
import HealthReportView from './views/HealthReportView.vue'
import LinkShareAccess from './views/LinkShareAccess.vue'
import LockScreen from './views/LockScreen.vue'
import PersonalActivityView from './views/PersonalActivityView.vue'
import SecretList from './views/SecretList.vue'
import SecretRequestFill from './views/SecretRequestFill.vue'

export default {
	// --- Flows (ADR-110 Decision 4). Only the SIDEBAR is an app component;
	//     the list and the canvas are the shared `flows` / `flow-detail`
	//     manifest page types. CnFlowSidebar has to mount in the NC app
	//     sidebar for the canvas to keep full width. ---
	FlowDetailSidebar: { kind: 'page', component: FlowDetailSidebar },

	LockScreen: { kind: 'page', component: LockScreen },
	SecretList: { kind: 'page', component: SecretList },
	SecretRequestFill: { kind: 'page', component: SecretRequestFill },
	LinkShareAccess: { kind: 'page', component: LinkShareAccess },
	EphemeralSendAccess: { kind: 'page', component: EphemeralSendAccess },
	ApplicationRegisterView: { kind: 'page', component: ApplicationRegisterView },
	ApplicationDetail: { kind: 'page', component: ApplicationDetail },
	PersonalActivityView: { kind: 'page', component: PersonalActivityView },
	HealthReportView: { kind: 'page', component: HealthReportView },
	EmergencyAccessView: { kind: 'page', component: EmergencyAccessView },
	CertificateInventoryView: { kind: 'page', component: CertificateInventoryView },
	'secret-create': {
		kind: 'modal',
		component: SecretCreateDialog,
		propsSchema: {},
	},
	'secret-edit': { kind: 'modal', component: SecretEditDialog, propsSchema: {} },
	'folder-create': {
		kind: 'modal',
		component: FolderCreateDialog,
		propsSchema: {},
	},
	'secret-delete': {
		kind: 'modal',
		component: SecretDeleteConfirmDialog,
		propsSchema: {},
	},
	'secret-move': { kind: 'modal', component: MoveDialog, propsSchema: {} },
	'secret-share': { kind: 'modal', component: SecretShareDialog, propsSchema: {} },
	'application-register': {
		kind: 'modal',
		component: ApplicationRegisterDialog,
		propsSchema: {},
	},
	'private-key-download': {
		kind: 'modal',
		component: PrivateKeyDownloadDialog,
		propsSchema: {},
	},
	'share-dialog': { kind: 'modal', component: ShareDialog, propsSchema: {} },
	'share-list': { kind: 'form-field', component: ShareList, propsSchema: {} },
	'group-share-form': {
		kind: 'modal',
		component: GroupShareForm,
		propsSchema: {},
	},
	'secret-request-form': {
		kind: 'modal',
		component: SecretRequestForm,
		propsSchema: {},
	},
	'secret-request-list': {
		kind: 'form-field',
		component: SecretRequestList,
		propsSchema: {},
	},
}
