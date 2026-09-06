/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test alias stub for `@conduction/nextcloud-vue`.
 *
 * The published library ships a fat bundle (its own @nextcloud/vue copy,
 * apexcharts, leaflet, codemirror, …) that neither resolves nor renders under
 * vitest's jsdom env. Views under test import only a handful of primitives
 * (CnIndexPage, CnBreadcrumbs, CnStatusBadge) and assert on emitted events +
 * the slots they pass down — not on the library's real markup. So we swap the
 * whole package for minimal Vue 3 render-function stubs.
 *
 * Rules (same as the @nextcloud/vue stub):
 *  - No template strings (vite ships the runtime-only build).
 *  - Forward the events the views listen to via `this.$emit`.
 *  - Render the slots the views provide so tests can probe the rows.
 *
 * ⚠️ Vue 3 conversion notes:
 *  - `render(h)` gets NO `h`; import it from `vue`.
 *  - vnode data is FLAT — `{ attrs: {...}, on: { click } }` becomes
 *    `{ 'data-testid': ..., onClick }`.
 *  - `this.$scopedSlots` was REMOVED; all slots (scoped or not) live on
 *    `this.$slots` and are functions. Reading `$scopedSlots` yields
 *    `undefined`, so a row would silently render with no badges and the
 *    assertion would quietly stop covering anything.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#15.2
 */

import { h } from 'vue'

/**
 * CnIndexPage stub — renders a primary add button (emits `add`), a per-object
 * list rendering the `#list-item` slot (or a `.cn-object-row` with the
 * `#row-badges` slot), and an empty-state div when there are no objects.
 */
const CnIndexPage = {
	name: 'CnIndexPage',
	props: [
		'objects',
		'schema',
		'listConfig',
		'loading',
		'pagination',
		'viewMode',
		'availableViewModes',
		'selectable',
		'addLabel',
		'addIcon',
		'inlineSearch',
		'searchValue',
		'searchPlaceholder',
		'showSortSelect',
		'sortSelectOptions',
		'sortSelectValue',
		'listLabel',
		'rowKey',
		'emptyText',
	],
	emits: ['add', 'row-click'],
	render() {
		const objects = this.objects || []
		const children = [
			h(
				'button',
				{
					'data-testid': 'cn-cta-primary',
					type: 'button',
					onClick: () => this.$emit('add'),
				},
				this.addLabel,
			),
		]

		if (objects.length === 0) {
			children.push(
				h('div', { class: 'cn-index-page__empty' }, this.emptyText),
			)
		} else {
			const rows = objects.map((object) => {
				const listItem = this.$slots['list-item']
				if (listItem) {
					return h('div', { class: 'cn-index-page__item' }, [
						listItem({ object }),
					])
				}
				const badges = this.$slots['row-badges']
				return h(
					'div',
					{
						class: 'cn-object-row',
						onClick: () => this.$emit('row-click', object),
					},
					badges ? [badges({ object })] : [],
				)
			})
			children.push(h('div', { class: 'cn-index-page__rows' }, rows))
		}

		return h(
			'div',
			{ class: 'cn-index-page', 'data-testid': 'cn-index-page' },
			children,
		)
	},
}

// CnFolderSidebar stub removed with the in-page folder pane (restyle
// Stage 7) — folder navigation lives in KeepiqAppNav's tree now.

/** CnStatusBadge stub — a pill span carrying the label. */
const CnStatusBadge = {
	name: 'CnStatusBadge',
	props: ['label', 'variant', 'size', 'solid', 'colorMap'],
	render() {
		return h(
			'span',
			{ class: 'cn-status-badge', 'data-variant': this.variant },
			this.label,
		)
	},
}

/**
 * CnBreadcrumbs stub — one span per crumb (label or icon name), the last
 * carrying aria-current="page", mirroring the real component's contract so
 * tests can assert on the rendered trail.
 */
const CnBreadcrumbs = {
	name: 'CnBreadcrumbs',
	props: ['crumbs', 'ariaLabel'],
	render() {
		const crumbs = this.crumbs || []
		if (crumbs.length === 0) return null
		return h(
			'nav',
			{ 'data-testid': 'cn-breadcrumbs', 'aria-label': this.ariaLabel },
			crumbs.map((crumb, index) =>
				h(
					'span',
					{
						class: 'cn-breadcrumbs__crumb',
						'aria-current':
							index === crumbs.length - 1 ? 'page' : undefined,
					},
					crumb.label || crumb.icon,
				),
			),
		)
	},
}

/**
 * CnIconColorPicker stub — a div carrying two buttons that emit the picker's
 * v-model events, so dialog specs can drive icon/color picks without the
 * library's catalogs.
 */
const CnIconColorPicker = {
	name: 'CnIconColorPicker',
	props: ['icon', 'color', 'fallbackIcon', 'translate'],
	emits: ['update:icon', 'update:color'],
	render() {
		return h('div', { 'data-testid': 'cn-icon-color-picker' }, [
			h('button', {
				type: 'button',
				'data-testid': 'stub-pick-icon',
				onClick: () => this.$emit('update:icon', 'briefcase'),
			}),
			h('button', {
				type: 'button',
				'data-testid': 'stub-pick-color',
				onClick: () => this.$emit('update:color', 'blue'),
			}),
			h('button', {
				type: 'button',
				'data-testid': 'stub-clear-style',
				onClick: () => {
					this.$emit('update:icon', null)
					this.$emit('update:color', null)
				},
			}),
		])
	},
}

/**
 * Catalog-resolver stubs (folder customization, restyle Stage 9). A
 * two-entry palette is enough for rendering specs; unknown keys resolve to
 * null exactly like the real resolvers, so fallback paths stay covered.
 */
const FOLDER_COLORS = [
	{ key: 'red', label: 'Red', light: '#c92020', dark: '#ff6b66' },
	{ key: 'blue', label: 'Blue', light: '#0064a3', dark: '#4fa8e6' },
]
const STUB_ICON = {
	name: 'StubFolderIcon',
	props: ['size', 'fillColor'],
	render() {
		return h('span', { 'data-stub': 'folder-icon' })
	},
}
const FOLDER_ICONS = [
	{ key: 'briefcase', label: 'Work', component: STUB_ICON },
	{ key: 'star', label: 'Star', component: STUB_ICON },
]

/**
 * Resolve a stub palette key to its theme variant (null when unknown).
 *
 * @param {string|null} value The stored color value.
 * @param {'dark'|'light'} theme The active theme.
 * @return {string|null} The hex or null.
 */
function resolveFolderColor(value, theme) {
	if (!value) return null
	if (value.startsWith('#')) return value
	const entry = FOLDER_COLORS.find((c) => c.key === value)
	if (!entry) return null
	return theme === 'dark' ? entry.dark : entry.light
}

/**
 * Resolve a stub icon key to its component (null when unknown).
 *
 * @param {string|null} key The stored icon key.
 * @return {object|null} The component or null.
 */
function resolveFolderIcon(key) {
	if (!key) return null
	return FOLDER_ICONS.find((e) => e.key === key)?.component ?? null
}

/**
 * Search the stub icon set (mirrors the real signature).
 *
 * @param {string} query The query.
 * @return {Array<object>} Matching entries.
 */
function searchFolderIcons(query) {
	const q = String(query || '').toLowerCase()
	if (q === '') return FOLDER_ICONS
	return FOLDER_ICONS.filter(
		(e) => e.key.includes(q) || e.label.toLowerCase().includes(q),
	)
}

/**
 * Tint stub (Proton circle): mirrors the real derive-from-the-same-hex
 * contract at a fixed alpha; null for unset/unknown.
 *
 * @param {string|null} value The stored color value.
 * @param {'dark'|'light'} theme The active theme.
 * @return {string|null} An rgba string or null.
 */
function folderColorTint(value, theme) {
	const hex = resolveFolderColor(value, theme)
	if (!hex) return null
	return `rgba(0, 0, 0, 0.15) /* ${hex} */`
}

/**
 * Non-reactive theme stub — jsdom has no NC theme attributes.
 *
 * @return {'light'} Always light.
 */
function currentTheme() {
	return 'light'
}

export {
	CnBreadcrumbs,
	CnIconColorPicker,
	CnIndexPage,
	CnStatusBadge,
	currentTheme,
	FOLDER_COLORS,
	FOLDER_ICONS,
	folderColorTint,
	resolveFolderColor,
	resolveFolderIcon,
	searchFolderIcons,
}
