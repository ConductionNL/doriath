<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Keepiq app shell — thin CnAppRoot wrapper (hydra ADR-024 / ADR-036).

  CnAppRoot handles the dependency check, manifest-driven CnAppNav,
  and per-route page dispatch (manifest.pages[].type →
  Cn{Dashboard,Settings,Index,Detail,…}Page). For keepiq the dashboard
  is rendered via the v2 widget grid (the manifest's Dashboard page
  carries `widgets[]` targeting the `body` slot, so CnPageRenderer
  short-circuits the default CnDashboardPage component and mounts
  CnWidgetGrid directly — matches the shillinq#166 pattern).

  Keepiq-specific concerns the shell still owns:
  - Pinia stores boot once (settings + object configure against
    OpenRegister).
  - Lock-screen gating: whenever the session locks, redirect to the
    Lock route. When the user navigates to `/lock` while unlocked
    (e.g. via the "Lock vault" footer menu entry), call
    `session.lock()` so the screen renders the unlock form rather
    than bouncing back to Dashboard.
  - Session-timeout polling + visibility/beforeunload key clearing.
  - User-settings dialog: the legacy UserSettings.vue contents are
    injected into CnAppRoot's `#user-settings` slot so the manifest
    entry `action: "user-settings"` opens the same Session / Security
    / Encryption sections the previous MainMenu footer item did.
-->
<template>
	<!-- Public surface: a recipient with no account, holding a fill link.
	     Rendered WITHOUT the app shell, because CnAppRoot's CnAppNav lists every
	     page in the manifest — measured on 2026-08-19, an anonymous recipient was
	     served "Dashboard, Vault, Password health, Certificates, Emergency access,
	     Settings…", none of which they can open. That is the app's whole feature
	     surface disclosed to a stranger, and a row of dead links around the one
	     task they came to do.

	     CnAppRoot hosts `<router-view>` itself, so skipping it means rendering the
	     route here. The library documents this as the supported path for a layout
	     that is not the app shell. `t()` is an app-level mixin from main.js, not a
	     CnAppRoot injection, so public views keep their translations.

	     The offline banner, migration banner, lock-screen gating and user-settings
	     dialog are all deliberately absent: every one of them speaks about a vault
	     the recipient has no account for. -->
	<div v-if="isPublicPage" class="keepiq-public-shell">
		<!-- Every route's component IS CnPageRenderer (see routesFromManifest in
		     main.js), and it takes its manifest, component registry, page types and
		     translator by INJECT — from CnAppRoot. Outside the shell those injections
		     do not exist, so the renderer mounts and finds no page: the first attempt
		     at this rendered a blank page and logged "[CnPageRenderer] No page found
		     for $route.name". Its props take precedence over inject, so they are
		     supplied here.

		     `v-bind="route.params"` is required with the v-slot form: the route
		     records set `props: true`, but vue-router only applies that itself when
		     it owns the rendering. Without it SecretRequestFill gets no `token`. -->
		<router-view v-slot="{ Component, route }">
			<component
				:is="Component"
				v-bind="route.params"
				:manifest="manifest"
				:customComponents="customComponents"
				:pageTypes="pageTypes"
				:translate="translateForApp" />
		</router-view>
	</div>

	<div
		v-else
		class="keepiq-shell"
		:class="{ 'keepiq-shell--locked': isLockScreen }">
		<!-- Stale-data banner (offline-readonly-cache §4.3): shown whenever the
		     vault is being served from the offline cache. -->
		<div
			v-if="offlineStore.servedFromCache"
			class="keepiq-offline-banner"
			data-testid="offline-stale-banner">
			{{
				t('keepiq', 'Offline — read-only. Last synced {when}.', {
					when: syncedLabel,
				})
			}}
		</div>

		<!-- An interrupted compromise recovery leaves the vault write-locked with
		     nothing else in the UI saying why, so this sits at shell level rather
		     than inside any one view. It renders nothing when no migration is in
		     progress. -->
		<MigrationResumeBanner />

		<CnAppRoot
			:aiCompanion="true"
			:supportDialog="showSupportDialog"
			:manifest="manifest"
			:customComponents="customComponents"
			:pageTypes="pageTypes"
			:registry="registry"
			appId="keepiq"
			:translate="translateForApp"
			:permissions="permissions">
			<!-- Keepiq's own left rail (restyle Stage 7): manifest-driven
			     entries PLUS the recursive vault/folder tree, which the
			     default CnAppNav cannot render. The `.keepiq-shell--locked`
			     rule below keeps hiding it on the lock screen. -->
			<template #menu>
				<KeepiqAppNav :manifest="manifest" />
			</template>
			<!-- Secret detail as a right sidebar over the vault list (restyle
			     Stage 8). CnAppRoot's #sidebar slot is the NcContent-level
			     mount NcAppSidebar needs to slide in correctly (ADR-017).
			     Open/closed state lives in the route's optional :id? segment
			     (src/utils/detailRoute.js), so /secrets/<id> deep links render
			     the list WITH the sidebar open and closing drops the segment.
			     The vault guard already gates these routes, so a locked vault
			     never reaches this render. -->
			<template #sidebar>
				<!-- No :key on purpose: switching rows swaps the prop and the
				     component reloads via its secretId watcher — remounting
				     would replay the slide-in animation on every row click. -->
				<SecretDetailSidebar
					v-if="detailSidebarSecretId"
					:secretId="detailSidebarSecretId"
					@close="closeDetailSidebar" />
			</template>
			<!-- User-settings dialog body (opened from the manifest's
		     `action: "user-settings"` menu entry via CnAppRoot's
		     cnOpenUserSettings inject). Sections preserve the legacy
		     UserSettings.vue surface unchanged. -->
			<template #user-settings>
				<NcAppSettingsSection id="session" :name="t('keepiq', 'Session')">
					<template #icon>
						<TimerIcon :size="20" />
					</template>
					<div class="user-settings__field">
						<NcSelect
							v-model="sessionTimeout"
							:options="timeoutOptions"
							:inputLabel="t('keepiq', 'Session timeout')"
							label="label"
							:reduce="(opt) => opt.value"
							@input="saveTimeout" />
					</div>
				</NcAppSettingsSection>

				<NcAppSettingsSection id="security" :name="t('keepiq', 'Security')">
					<template #icon>
						<ShieldIcon :size="20" />
					</template>
					<div class="user-settings__field">
						<MasterPasswordForm />
					</div>
					<div class="user-settings__field">
						<PasskeyManager />
					</div>
					<div class="user-settings__field">
						<NcButton
							variant="error"
							@click="showRecovery = !showRecovery">
							{{ t('keepiq', 'My master password was compromised') }}
						</NcButton>
						<CompromiseRecoveryForm v-if="showRecovery" />
					</div>
				</NcAppSettingsSection>

				<NcAppSettingsSection
					id="encryption"
					:name="t('keepiq', 'Encryption')">
					<template #icon>
						<KeyIcon :size="20" />
					</template>
					<div
						v-if="suiteStore.currentSuite"
						class="user-settings__suite-info">
						<p>
							<strong>{{ t('keepiq', 'Status') }}:</strong>
							{{ suiteStore.currentSuite.status }}
						</p>
						<p>
							<strong>{{ t('keepiq', 'Created') }}:</strong>
							{{ suiteStore.currentSuite.createdAt }}
						</p>
						<p>
							<strong>{{ t('keepiq', 'Suite ID') }}:</strong>
							{{ suiteStore.currentSuite.id }}
						</p>

						<template v-if="suiteStore.currentSuite.status === 'active'">
							<NcNoteCard v-if="revokeConfirm" type="warning">
								{{
									t(
										'keepiq',
										'Revoking your encryption suite will make all your secrets inaccessible until an administrator reinstates it. This cannot be undone by you.',
									)
								}}
								<div style="margin-top: 0.5rem">
									<NcTextField
										v-model="revokeReason"
										:label="t('keepiq', 'Reason for revocation')"
										:placeholder="
											t(
												'keepiq',
												'e.g. Device lost, key compromised',
											)
										" />
								</div>
								<div
									style="
										display: flex;
										gap: 0.5rem;
										margin-top: 0.5rem;
									">
									<NcButton
										variant="error"
										:disabled="!revokeReason || revoking"
										@click="handleRevoke">
										{{
											revoking
												? t('keepiq', 'Revoking…')
												: t('keepiq', 'Confirm revocation')
										}}
									</NcButton>
									<NcButton
										variant="secondary"
										@click="revokeConfirm = false">
										{{ t('keepiq', 'Cancel') }}
									</NcButton>
								</div>
							</NcNoteCard>
							<NcButton
								v-else
								variant="warning"
								@click="revokeConfirm = true">
								{{ t('keepiq', 'Revoke encryption suite') }}
							</NcButton>
						</template>

						<NcNoteCard v-if="revokeSuccess" type="success">
							{{
								t(
									'keepiq',
									'Encryption suite revoked. Contact an administrator to reinstate it.',
								)
							}}
						</NcNoteCard>
						<NcNoteCard v-if="revokeError" type="error">
							{{ revokeError }}
						</NcNoteCard>
					</div>
					<NcEmptyContent
						v-else
						:name="t('keepiq', 'No encryption suite')"
						:description="
							t('keepiq', 'Unlock the vault to set up encryption')
						">
						<template #icon>
							<KeyIcon :size="64" />
						</template>
					</NcEmptyContent>
				</NcAppSettingsSection>

				<NcAppSettingsSection
					id="browser-extension"
					:name="t('keepiq', 'Browser extension')">
					<template #icon>
						<PuzzleIcon :size="20" />
					</template>
					<p>
						{{
							t(
								'keepiq',
								'The Keepiq browser extension autofills your logins, provides passkeys, and shows TOTP codes — decrypting everything inside the extension. The server only ever ships encrypted blobs, so your master password and secrets never leave your device.',
							)
						}}
					</p>
					<ol class="user-settings__steps">
						<li>
							{{
								t(
									'keepiq',
									'Install the Keepiq extension for your browser.',
								)
							}}
						</li>
						<li>
							{{
								t(
									'keepiq',
									'Create a dedicated app password in Nextcloud security settings (never use your login password).',
								)
							}}
						</li>
						<li>
							{{
								t(
									'keepiq',
									'In the extension, enter this server URL, your username, and the app password, then unlock with your master password.',
								)
							}}
						</li>
					</ol>
					<div class="user-settings__field">
						<NcButton :href="securitySettingsUrl" variant="secondary">
							{{ t('keepiq', 'Open Nextcloud security settings') }}
						</NcButton>
					</div>
					<p class="user-settings__hint">
						{{
							t(
								'keepiq',
								'Revoke the app password in Nextcloud security settings at any time to disconnect the extension.',
							)
						}}
					</p>
				</NcAppSettingsSection>

				<!-- Version footer (restyle stage 3): a plain trailing line,
				     matching the legacy UserSettings.vue surface. Hidden when
				     the initial state is absent. -->
				<p v-if="appVersion" class="user-settings__version">
					{{ t('keepiq', 'Keepiq {version}', { version: appVersion }) }}
				</p>
			</template>
		</CnAppRoot>
	</div>
</template>

<script>
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as ncT } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppSettingsSection,
	NcButton,
	NcEmptyContent,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import PuzzleIcon from 'vue-material-design-icons/Puzzle.vue'
import ShieldIcon from 'vue-material-design-icons/Shield.vue'
import TimerIcon from 'vue-material-design-icons/Timer.vue'
import CompromiseRecoveryForm from './components/CompromiseRecoveryForm.vue'
import KeepiqAppNav from './components/KeepiqAppNav/KeepiqAppNav.vue'
import MasterPasswordForm from './components/MasterPasswordForm.vue'
import MigrationResumeBanner from './components/MigrationResumeBanner.vue'
import PasskeyManager from './components/PasskeyManager.vue'
import SecretDetailSidebar from './components/SecretDetailSidebar.vue'
import {
	handleLockTransition,
	isPublicRoute,
	isPublicSurface,
	LOCK_ROUTE_NAME,
} from './router/guards.js'
import { useEncryptionSuiteStore } from './store/modules/encryptionSuite.js'
import { useOfflineStore } from './store/modules/offline.js'
import { useSessionStore } from './store/modules/session.js'
import { initializeStores } from './store/store.js'
import { activeDetailSecretId, closeDetailLocation } from './utils/detailRoute.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		NcAppSettingsSection,
		NcButton,
		NcEmptyContent,
		NcNoteCard,
		NcSelect,
		NcTextField,
		TimerIcon,
		ShieldIcon,
		KeyIcon,
		PuzzleIcon,
		MasterPasswordForm,
		PasskeyManager,
		CompromiseRecoveryForm,
		KeepiqAppNav,
		MigrationResumeBanner,
		SecretDetailSidebar,
	},

	props: {
		/**
		 * Manifest object — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},

		/**
		 * Consumer-injected components used by `type: "custom"` pages
		 * (page.component). Derived from the `kind:"page"` entries of
		 * src/registry.js in src/main.js.
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 * Wired through to descendant `CnPageRenderer` instances.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},

		/**
		 * 5-kind component registry for v2 manifests (hydra ADR-036).
		 * Map of registry key → `{ kind, component, ...metadata }`.
		 * See src/registry.js for the keepiq entries.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			/**
			 * True while the page is unloading (beforeunload fired). The
			 * lock-redirect watcher skips its redirect in that window so
			 * leaving Keepiq for another app doesn't flash the unlock
			 * form mid-transition — the key is still cleared.
			 */
			unloading: false,
			/**
			 * App version for the user-settings dialog footer, provided by
			 * DashboardController::page() from appinfo/info.xml. Empty when
			 * the initial state is absent (e.g. public surfaces), which
			 * hides the footer line entirely.
			 */
			appVersion: loadState('keepiq', 'appVersion', ''),
			storesReady: false,
			timeoutInterval: null,
			sessionTimeout: 'session',
			showRecovery: false,
			revokeConfirm: false,
			revokeReason: '',
			revoking: false,
			revokeSuccess: false,
			revokeError: null,
			timeoutOptions: [
				{ value: 'session', label: ncT('keepiq', 'Nextcloud session') },
				{ value: '10min', label: ncT('keepiq', '10 minutes') },
				{ value: '30min', label: ncT('keepiq', '30 minutes') },
			],
		}
	},

	computed: {
		/**
		 * Whether this page is being served to an anonymous recipient.
		 *
		 * Selects the shell-free layout. Decided from the URL for the same reason
		 * `showSupportDialog` is: the value is needed on the very first render, and
		 * `$route` is not resolved yet at that point — a computed keyed on
		 * `$route.name` reads undefined exactly when it matters, which is the bug
		 * the support-note fix already walked into once.
		 *
		 * Failing "public" is the safe direction here: an authenticated user
		 * mis-detected as public would lose their navigation, which is visible and
		 * reported immediately. The reverse — a stranger served the full app nav —
		 * is what shipped unnoticed until it was measured in a browser.
		 *
		 * @return {boolean} True on the anonymous recipient surfaces.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 */
		isPublicPage() {
			return isPublicSurface(window.location)
		},

		/**
		 * Whether the first-open support note may be shown.
		 *
		 * False on every anonymous recipient surface. Those pages are opened by
		 * people who are not our users at all — someone filling in a credential we
		 * asked them for, or opening a share — and a donation appeal mid-task is at
		 * best noise, at worst a phishing tell on a page about to receive a secret.
		 *
		 * Decided from the URL rather than from `$route`, and that is not a
		 * shortcut: CnAppRoot reads this prop ONCE in `setup()`
		 * (`props.supportDialog === false` selects whether the dialog is wired at
		 * all), which happens before the router resolves the initial navigation. A
		 * reactive computed keyed on `$route.name` is therefore still undefined at
		 * the only moment the value is read — which is exactly why the first attempt
		 * at this had no effect.
		 *
		 * `/apps/keepiq/public` is the anonymous shell, so anything served from it
		 * is a recipient page by definition. The hash prefixes cover the same routes
		 * reached on the authenticated shell.
		 *
		 * The library's own guard is not enough: it opens the note only on a
		 * DEFINITIVE "not seen" answer, but the preference request 401s for an
		 * anonymous visitor, and a 401 is not a definitive no.
		 *
		 * @return {boolean} False on public recipient pages.
		 *
		 * @spec exclude Host-level suppression of a library affordance. No
		 *   requirement describes the support note; what matters is that the public
		 *   recipient pages stay task-only, which the view specs assert.
		 */
		showSupportDialog() {
			return (
				isPublicSurface(window.location) === false
				&& isPublicRoute(this.$route) === false
			)
		},

		/**
		 * Current Nextcloud user permissions, surfaced to the app shell.
		 *
		 * @return {Array} Permission list (empty when unauthenticated).
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},

		/**
		 * Link to Nextcloud personal security settings, where the extension's
		 * app password is created and revoked (browser-extension-autofill §5.1).
		 *
		 * @return {string}
		 */
		securitySettingsUrl() {
			return generateUrl('/settings/user/security')
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia session store with no domain logic.
		 */
		sessionStore() {
			return useSessionStore()
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia encryption-suite store with no domain logic.
		 */
		suiteStore() {
			return useEncryptionSuiteStore()
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia offline store with no domain logic.
		 */
		offlineStore() {
			return useOfflineStore()
		},

		/**
		 * Human last-sync label for the stale banner.
		 *
		 * @return {string}
		 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-a-stale-data-banner-shows-the-last-sync-time
		 */
		syncedLabel() {
			return this.offlineStore.syncedAt
				? new Date(this.offlineStore.syncedAt).toLocaleString()
				: ncT('keepiq', 'unknown')
		},

		/**
		 * Whether the vault is locked (no in-memory CryptoKey).
		 *
		 * @return {boolean} True when locked.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		isLocked() {
			return this.sessionStore.isLocked
		},

		/**
		 * The secret id whose detail sidebar is open, or null. Derived from
		 * the list routes' optional `:id?` segment (restyle Stage 8) — other
		 * id-carrying routes (ApplicationDetail) never open it.
		 *
		 * @return {string|null} The open secret's id, or null when closed.
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		detailSidebarSecretId() {
			return activeDetailSecretId(this.$route)
		},

		/**
		 * Whether the current route is the lock/setup screen. Drives the
		 * `--locked` shell modifier that hides the app navigation: the
		 * unlock prompt must be the only interactive surface, and no
		 * z-index inside NcAppContent can cover a sibling NcAppNavigation
		 * (each is its own stacking context), so the nav is hidden at the
		 * shell level instead.
		 *
		 * @return {boolean} True on the Lock route.
		 */
		isLockScreen() {
			return this.$route?.name === LOCK_ROUTE_NAME
		},
	},

	watch: {
		/**
		 * Eviction path: the vault can lock while the user is sitting
		 * still on an already-resolved route (session timeout, or the
		 * "Lock vault" menu entry). No navigation occurs in that case,
		 * so `createVaultGuard` never runs and this watcher owns the
		 * redirect. The guard covers the entry path; this covers the
		 * mid-session path.
		 *
		 * @param {boolean} locked New lock state.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
		 */
		isLocked(locked) {
			// Page is going away (app switch, tab close): the beforeunload
			// key clear triggered this transition, and redirecting a dying
			// page only flashes the unlock form over the outgoing view. The
			// fail-safe in handleBeforeUnload still redirects if the unload
			// turns out not to happen.
			if (this.unloading) {
				return
			}
			// The decision lives in guards.js so it can be unit-tested without
			// mounting the shell — see handleLockTransition.
			handleLockTransition(locked, this.$route, this.$router)
			// Write-through the encrypted offline snapshot on each ONLINE unlock
			// (offline-readonly-cache §2.3). Fail-soft — never blocks the session.
			if (
				!locked
				&& this.offlineStore.online
				&& !this.offlineStore.servedFromCache
			) {
				this.offlineStore.syncNow().catch(() => {})
			}
		},

		/**
		 * Inverse direction: if the user navigates to `/lock` while
		 * still unlocked (the "Lock vault" footer menu entry), call
		 * `session.lock()` so LockScreen renders the unlock form
		 * rather than the redirect-back path. Skipped when there's a
		 * returnUrl query (that path is the post-lock redirect we
		 * just emitted above and the session is already locked).
		 *
		 * @param {object} to Target route.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		$route(to) {
			if (
				to?.name === 'Lock'
				&& !this.sessionStore.isLocked
				&& !to.query?.returnUrl
				&& this.suiteStore.currentSuite
			) {
				this.sessionStore.lock()
			}
		},
	},

	/**
	 * Boot the app shell: initialise stores, warm the offline cache, and
	 * start the session-timeout poll + listeners.
	 *
	 * The boot-time lock redirect that used to live here is gone. It ran
	 * after `await initializeStores()` — i.e. after a settings round-trip
	 * — by which time the routed page had already mounted and fetched, so
	 * the vault inventory painted before the lock screen replaced it. The
	 * gate is now `createVaultGuard` in src/router/guards.js, registered
	 * as a synchronous `beforeEach` so a locked vault never resolves a
	 * protected route in the first place.
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
	 */
	async created() {
		await initializeStores()
		this.storesReady = true

		// Offline cache bootstrap (offline-readonly-cache §2.4/§3.2): track
		// connectivity, arm the lock-time purge hook, and register the app-shell
		// service worker (feature-detected; a failed registration is a no-op
		// online-only fallback, never a hard error).
		this.offlineStore.bindConnectivity()
		this.offlineStore.ensureLockHook()
		this.registerServiceWorker()
		// If we booted already unlocked and online, warm the snapshot.
		if (!this.sessionStore.isLocked && this.offlineStore.online) {
			this.offlineStore.syncNow().catch(() => {})
		}

		// Poll every 10 s for session-timeout expiry.
		this.timeoutInterval = setInterval(() => {
			this.sessionStore.checkTimeout()
		}, 10000)

		// Re-check on tab visibility change (covers laptop-suspend cases).
		document.addEventListener('visibilitychange', this.handleVisibilityChange)

		// Best-effort key clear on tab close.
		window.addEventListener('beforeunload', this.handleBeforeUnload)
	},

	/**
	 * Tear down the session-timeout poll and event listeners on unmount.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
	 */
	beforeUnmount() {
		if (this.timeoutInterval) {
			clearInterval(this.timeoutInterval)
		}
		document.removeEventListener('visibilitychange', this.handleVisibilityChange)
		window.removeEventListener('beforeunload', this.handleBeforeUnload)
	},

	methods: {
		/**
		 * Close the secret-detail sidebar: return to the same list view
		 * (root or folder) without the `:id` segment, so bookmarks/back
		 * behave like plain navigation.
		 *
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		closeDetailSidebar() {
			this.$router.push(closeDetailLocation(this.$route))
		},

		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import
		 * so the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec exclude Pure i18n wrapper around Nextcloud translate — no domain logic.
		 */
		translateForApp(key) {
			return ncT('keepiq', key)
		},

		/**
		 * Re-check the session timeout when the tab regains focus
		 * (covers laptop-suspend cases where timers stall).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		handleVisibilityChange() {
			if (document.visibilityState === 'visible') {
				this.sessionStore.checkTimeout()
			}
		},

		/**
		 * Best-effort clear of the in-memory key when the tab closes or the
		 * user navigates to another app. The key clear is unconditional; the
		 * `unloading` flag only suppresses the lock-redirect (see the
		 * isLocked watcher) so the outgoing page doesn't flash the unlock
		 * form. beforeunload can fire WITHOUT the page actually unloading
		 * (e.g. a canceled navigation or a download), so a timer re-arms the
		 * redirect: if the page is still alive shortly after, the normal
		 * lock transition runs after all and the lock screen appears.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		handleBeforeUnload() {
			this.unloading = true
			this.sessionStore.lock()
			setTimeout(() => {
				this.unloading = false
				handleLockTransition(
					this.sessionStore.isLocked,
					this.$route,
					this.$router,
				)
			}, 2000)
		},

		/**
		 * Persist the chosen session-timeout preference into the store
		 * (mapping the enum to a millisecond duration).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		saveTimeout() {
			const timeouts = { session: 0, '10min': 600000, '30min': 1800000 }
			this.sessionStore.timeout = timeouts[this.sessionTimeout] || 600000
		},

		/**
		 * Revoke the current user's encryption suite from the app shell,
		 * surfacing success/error state to the UI.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async handleRevoke() {
			this.revoking = true
			this.revokeError = null
			this.revokeSuccess = false

			try {
				await this.suiteStore.revokeSuite(this.revokeReason)
				this.revokeSuccess = true
				this.revokeConfirm = false
				this.revokeReason = ''
			} catch (e) {
				this.revokeError =
					e.response?.data?.message
					|| e.message
					|| ncT('keepiq', 'Failed to revoke suite')
			} finally {
				this.revoking = false
			}
		},

		/**
		 * Register the offline app-shell service worker, feature-detected.
		 * A registration failure (unsupported context, scope rejected) is a
		 * no-op online-only fallback — never a hard error.
		 *
		 * @return {void}
		 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-the-app-shell-loads-offline-via-a-service-worker
		 */
		registerServiceWorker() {
			if (
				typeof navigator === 'undefined'
				|| !('serviceWorker' in navigator)
			) {
				return
			}
			// Served by ServiceWorkerController at the app root so the browser
			// gets the correct JS MIME and the worker's default scope is the
			// whole SPA (offline-readonly-cache §3.2).
			const swUrl = generateUrl('/apps/keepiq/serviceworker.js')
			navigator.serviceWorker.register(swUrl).catch(() => {
				// Online-only fallback; the offline cache is simply absent.
			})
		},
	},
}
</script>

<style scoped>
/*
 * Anonymous recipient shell: centre the single card each public view
 * renders. The guest layout gives the page a full-bleed background and
 * mounts the app top-left with no chrome, so without this the card sat
 * pinned in the corner. Flex + `margin: auto` on the child (the routed
 * view's root) centres both axes AND stays scrollable when the card is
 * taller than the viewport — unlike `align-items: center`, which clips
 * overflowing flex children at the top edge.
 */
.keepiq-public-shell {
	box-sizing: border-box;
	display: flex;
	min-height: 100vh;
	width: 100%;
	padding: clamp(16px, 4vh, 48px) 16px;
}

.keepiq-public-shell > :deep(*) {
	margin: auto;
}

/*
 * Lock/setup route: the master-password prompt must be the only visible
 * and interactive surface. LockScreen.vue's fixed overlay covers the
 * content area, but NcAppNavigation is a SIBLING stacking context that
 * always paints above it — so the nav (and its floating toggle) are
 * removed here at the shell level while the Lock route is active.
 */
/*
 * `.app-navigation` is the OUTER container div (@nextcloud/vue 9 wraps
 * `nav#app-navigation-vue` in it) — hiding only the inner nav leaves the
 * empty sidebar panel standing, so target the container; the floating
 * toggle button lives inside it and disappears with it.
 */
.keepiq-shell--locked :deep(.app-navigation) {
	display: none;
}

.user-settings__field {
	margin-bottom: 1rem;
}

.user-settings__suite-info p {
	margin: 0.25rem 0;
}

.user-settings__version {
	margin-top: 1rem;
	color: var(--color-text-maxcontrast);
}

.keepiq-offline-banner {
	position: sticky;
	top: 0;
	z-index: 2000;
	padding: 6px 16px;
	text-align: center;
	font-weight: bold;
	/*
	 * --color-warning is the pale tint (#FFEEC5 in light), so the primary
	 * element foreground — white for the default primary — landed at roughly
	 * 1.3:1 on it. Dark mode was fine (#3D3010), making this the same
	 * one-theme failure as the rest of this change. The paired *-text value is
	 * the one the theme flips alongside the tint.
	 *
	 * This banner is sticky at z-index 2000 and is the app's only signal that
	 * the data on screen is stale and read-only, so it is the last thing that
	 * should be invisible.
	 */
	color: var(--color-warning-text);
	background-color: var(--color-warning);
}
</style>
