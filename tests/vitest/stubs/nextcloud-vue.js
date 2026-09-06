/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test alias stub for the entire `@nextcloud/vue` design-system package.
 *
 * The published `@nextcloud/vue` ships a fat ESM bundle that pulls in
 * `@nextcloud/capabilities` + `@nextcloud/initial-state` + an `imagePath`
 * helper from the NC window globals. None of that is set up under
 * vitest's jsdom env, so importing `@nextcloud/vue` blows up with
 * `TypeError: imagePath is not a function`.
 *
 * Tests that mount components which import from `@nextcloud/vue` don't
 * care about its rendered markup — they assert on emitted events and
 * surrounding markup. So we replace the whole package with a flat dict
 * of minimal Vue 3 stub components. The stubs:
 *
 *  - DON'T use template strings (vite ships the runtime-only build, which
 *    has no template compiler).
 *  - DO forward the events the app listens to via `this.$emit`.
 *  - DO render their slots so the test can probe nested markup.
 *
 * ⚠️ Vue 3 conversion notes — every one of these is a SILENT failure if
 * missed, because a wrong vnode shape renders empty rather than throwing:
 *   - `render(h)` receives NO `h` argument; import `h` from `vue`.
 *   - vnode data is FLAT: Vue 2's `{ attrs: {...}, on: { click } }` becomes
 *     `{ 'data-stub': ..., onClick }`.
 *   - `this.$slots.default` is a FUNCTION in Vue 3, not an array. Rendering
 *     it directly emits nothing and `.length` is its ARITY, not a child
 *     count — call it.
 *
 * Prop names track @nextcloud/vue v9: field components model through
 * `modelValue`, and NcButton's style prop is `variant` (`type` is the
 * NATIVE button type). A stub must be strictly more faithful than the real
 * component, never more permissive.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13
 */

import { h } from 'vue'

/** Render every slot this design system commonly exposes, in a stable order. */
function slotChildren(vm) {
	return [vm.$slots.default?.(), vm.$slots.actions?.(), vm.$slots.icon?.()]
}

function passthrough(name) {
	return {
		name,
		props: [
			'modelValue',
			'value',
			'open',
			'type',
			'variant',
			'size',
			'disabled',
			'inputLabel',
			'options',
			'reduce',
			'clearable',
			'ariaLabel',
			'name',
			'label',
		],
		emits: ['update:modelValue', 'update:open', 'input'],
		render() {
			return h('div', { 'data-stub': name }, slotChildren(this))
		},
	}
}

function buttonLike(name) {
	return {
		name,
		props: ['type', 'variant', 'disabled', 'ariaLabel'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{
					disabled: this.disabled,
					'data-stub': name,
					'aria-label': this.ariaLabel,
					onClick: (e) => this.$emit('click', e),
				},
				[this.$slots.icon?.(), this.$slots.default?.()],
			)
		},
	}
}

export const NcDialog = {
	name: 'NcDialog',
	props: ['name', 'open', 'size'],
	emits: ['update:open', 'closing'],
	render() {
		return h(
			'div',
			{ 'data-stub': 'NcDialog', 'data-open': String(!!this.open) },
			[this.$slots.default?.(), this.$slots.actions?.()],
		)
	},
}

export const NcModal = passthrough('NcModal')
export const NcButton = buttonLike('NcButton')
export const NcSelect = passthrough('NcSelect')
export const NcNoteCard = passthrough('NcNoteCard')
export const NcLoadingIcon = {
	name: 'NcLoadingIcon',
	props: ['size'],
	render() {
		return h('span', { 'data-stub': 'NcLoadingIcon' })
	},
}
export const NcInputField = passthrough('NcInputField')
export const NcTextField = passthrough('NcTextField')
export const NcTextArea = passthrough('NcTextArea')
export const NcPasswordField = passthrough('NcPasswordField')
export const NcEmptyContent = passthrough('NcEmptyContent')
export const NcAppNavigation = passthrough('NcAppNavigation')
export const NcAppContent = passthrough('NcAppContent')
export const NcCheckboxRadioSwitch = passthrough('NcCheckboxRadioSwitch')

export default {
	NcDialog,
	NcModal,
	NcButton,
	NcSelect,
	NcNoteCard,
	NcLoadingIcon,
	NcInputField,
	NcTextField,
	NcTextArea,
	NcPasswordField,
	NcEmptyContent,
	NcAppNavigation,
	NcAppContent,
	NcCheckboxRadioSwitch,
}
