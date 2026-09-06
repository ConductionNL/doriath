/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Keepiq Tier-4 bootstrap (hydra ADR-024 / ADR-036).
 *
 * Builds the vue-router from the bundled manifest, registers lib
 * icons + translations, and mounts the CnAppRoot-driven shell at
 * `#keepiq-app`.
 *
 * ⚠️ The host element is `#keepiq-app`, NOT `#content`. Under Vue 2,
 * `$mount('#content')` REPLACED the matched element, so the template's
 * `<div id="content">` (a duplicate of core's `layout.user.php` wrapper)
 * was swallowed. Vue 3's `mount()` renders INSIDE the match instead, so
 * mounting on `#content` would nest the app in core's own wrapper. The
 * host element is renamed rather than reasoning about which div wins.
 */

// No per-name `eslint-disable` for `import/named`: the flat config does not
// register that rule, so each comment was ITSELF an error ("Definition for
// rule 'import/named' was not found") — six of them in this one block.
import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	invalidateEndpointSourceCache,
	registerBuiltinDashboardWidgets,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp, h } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import {
	applyHashRouteHandoff,
	matchRouterBase,
} from './bootstrap/hash-route-handoff.js'
import { ensureSkipActionsTarget } from './bootstrap/skip-actions.js'
import appIcons from './icons.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import pinia from './pinia.js'
import registry from './registry.js'
import { createVaultGuard, manifestForLockState } from './router/guards.js'
import { useSessionStore } from './store/modules/session.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
// Global (unscoped) app styles
import './assets/app.css'

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)

// nc-vue declares `sideEffects: ["**/*.css"]`, which lets webpack drop the
// bare imports that register the built-in `stat` / `object-table` dashboard
// widgets. Without this explicit call those widgets render "Widget not
// available" while `chart` (registered inline) keeps working — the asymmetry
// that identified the bug on larpingapp.
registerBuiltinDashboardWidgets()

try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[keepiq] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow
// the JS/CSS allowlist through Apache; /custom_apps/<app>/l10n/<locale>.json
// may 404. Strings fall back to English source on miss; boot must not
// depend on this resolving.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('keepiq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// frozen / non-extensible (webpack ESM module records) and vue-router
// attaches bookkeeping to the component options it is handed. Cloning
// gives the router an extensible options object without altering the
// lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

// `require.context` is a WEBPACK build-time API, not CommonJS `require`: the
// bundler rewrites this call at compile time and no `require` exists at
// runtime. eslint's browser globals therefore report `no-undef` correctly —
// the code is right and the linter is right. Scoped to this one identifier so
// a genuinely undefined name elsewhere in the file still fails.
/* global require */
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx
	.keys()
	.sort()
	.map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

/**
 * Build the vue-router config from the manifest. Each manifest page
 * becomes one route; the route's `name` IS `page.id` (per the lib's
 * manifest contract). Routes whose path declares a `:` parameter pass
 * `props: true` so the renderer receives params as props.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 4 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to dashboard. vue-router 4 REMOVED the bare `*`
	// path — it matches nothing and silently leaves `<router-view>` empty
	// with no error, so the named-param form is mandatory.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

/**
 * The router base for THIS page load.
 *
 * The two-form problem (`/apps/keepiq/...` vs `/index.php/apps/keepiq/...`)
 * and the `/public` extension on the anonymous shell are documented on
 * `matchRouterBase` — the fallback to `generateUrl()` only covers a pathname
 * that is not a Keepiq URL at all (component-test mounts, mainly).
 *
 * On the anonymous shell the base includes `/public`, so the SAME manifest
 * routes (`/share/link/:token`, `/send/:token`, `/share/request/:token`)
 * resolve on both shells: the recipient links the builders now emit
 * (`/apps/keepiq/public/share/link/<token>`) are served by
 * `publicShell#pageCatchAll` and match here.
 *
 * It matters more here than elsewhere: keepiq's share and send links are
 * handed to people OUTSIDE the app, in messages and emails, in whichever URL
 * shape the sender's client produced. A swallowed deep link there is a share
 * that silently does nothing.
 *
 * @return {string} The base path vue-router should strip from the URL.
 */
function routerBase() {
	return matchRouterBase(window.location.pathname) ?? generateUrl('/apps/keepiq')
}

// Legacy hash links (`/public#/share/link/<token>` and friends) predate the
// move to createWebHistory and cannot be regenerated — they sit in
// recipients' inboxes. Rewrite them to the path form IN PLACE, and do it
// BEFORE createRouter(): the history reads the location at construction.
applyHashRouteHandoff()

const router = createRouter({
	history: createWebHistory(routerBase()),
	routes: routesFromManifest(mergedManifest),
})

// Keep every ROUTED application screen behind the master password. This must
// be a router guard rather than an App.vue lifecycle redirect: `beforeEach`
// runs before the route resolves, so a locked vault never instantiates a page
// component and never issues its `mounted()` fetches. The store is passed as
// a lazy factory because the guard is registered before `app.use(pinia)`;
// `pinia` is passed explicitly so the guard never depends on an active-Pinia
// context.
//
// "Routed" is the exact scope. Every manifest page gets
// `component: RoutePageRenderer` above and CnAppRoot renders them through a
// single `<router-view>`, so no manifest-driven page mounts outside route
// resolution. Shell-level SIBLINGS of that `<router-view>` are outside this
// guard by construction — today that is `CnAiCompanion` (mounted by
// CnAppRoot when App.vue passes `:ai-companion`, and it issues its own
// health request on created()) and App.vue's offline banner. Neither is
// secret-bearing and neither holds a key, but anything added there needs its
// own gating: this guard will not cover it.
router.beforeEach(createVaultGuard(() => useSessionStore(pinia)))

// The dashboard's endpoint-bound widgets (KPI stats, activity feed, pending
// queue) fetch through the library's shared endpoint cache, which holds
// responses for 5 minutes. Fine within a page, but wrong ACROSS a
// navigation: create a secret and come back, and the dashboard still shows
// the pre-creation totals and an activity feed without the event. Dropping
// the cache whenever a navigation lands on the dashboard makes every visit
// current, while same-page dedupe/caching keeps working.
router.afterEach((to) => {
	if (to.name === 'Dashboard') {
		invalidateEndpointSourceCache()
	}
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib
// exports `defaultPageTypes` (and the app's component maps) FROZEN, so
// any consumer that writes to them throws. Cloning here yields
// extensible objects.
//
// `customComponents` is derived from the v2 registry's `kind:"page"`
// entries because CnPageRenderer's `type:"custom"` dispatch path still
// resolves through `customComponents` (see ADR-036 transition notes).
// `kind:"widget"` entries in `registry.js` are consumed directly from
// the `registry` prop by CnAppRoot → CnWidgetGrid.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }
const customComponentsProp = Object.fromEntries(
	Object.entries(registryProp)
		.filter(([, entry]) => entry && entry.kind === 'page')
		.map(([key, entry]) => [key, entry.component]),
)

// Create and mount the app immediately so the shell renders.
const app = createApp({
	// `manifestForLockState` withholds the walkthrough while the vault is
	// locked. Reading `isLocked` here makes it a render dependency, so the tour
	// reappears the moment the vault is unlocked — see the note on that helper
	// for why the router guard above cannot cover this.
	render: () =>
		h(App, {
			manifest: manifestForLockState(mergedManifest, useSessionStore(pinia)),
			customComponents: customComponentsProp,
			pageTypes: pageTypesProp,
			registry: registryProp,
		}),
})

// `t` / `n` were provided by a global `Vue.mixin` under Vue 2. An app-level
// mixin is the direct Vue 3 equivalent and keeps `t(...)` available to every
// template without touching 86 components.
app.mixin({ methods: { t, n } })
app.use(pinia)
app.use(router)

// MUST run before mount. NcContent teleports its skip-link into `#skip-actions`,
// which only Nextcloud's authenticated layout provides; on the anonymous
// `/public` shell the target is absent and Vue's null-Teleport error aborted
// CnAppRoot's mount mid-update, leaving every public route on a permanent
// loading spinner. See src/bootstrap/skip-actions.js for the full trace.
ensureSkipActionsTarget()

app.mount('#keepiq-app')
