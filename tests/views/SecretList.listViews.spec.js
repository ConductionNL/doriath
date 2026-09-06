/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cards and table views used to fall back to CnIndexPage's generic
 * renderer with a name+url schema, so the type icon and strength badge the
 * List view carries were simply absent there (fix-brief bugs 8+9). The fix
 * declares Type and Strength columns in `listSchema` (cells rendered through
 * the `#column-type` / `#column-strength` slots — their values live in the
 * type/health stores, not on the row object) and fills the `#card` slot with
 * the same row component the list uses.
 *
 * Options-object style (like SecretList.registryDispatch.spec.js): mounting
 * the whole list view drags in the folder tree, search and type catalogue,
 * none of which the schema/column contract touches.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import SecretList from '../../src/views/SecretList.vue'
import { useHealthStore } from '../../src/store/modules/health.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

describe('SecretList — cards/table columns (fix-brief bugs 8+9)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('declares Type and Strength columns for the table, in a pinned order', () => {
		const schema = SecretList.computed.listSchema.call({})
		const props = schema.properties

		// The columns the table derives from the schema — columnsFromSchema
		// sorts by `order` hint and falls back to ALPHABETICAL, which would
		// interleave the new columns (name, strength, type, url).
		expect(Object.keys(props)).toEqual(['name', 'type', 'strength', 'url'])
		expect(props.name.order).toBeLessThan(props.type.order)
		expect(props.type.order).toBeLessThan(props.strength.order)
		expect(props.strength.order).toBeLessThan(props.url.order)

		// The card/name/description wiring must survive the new columns.
		expect(schema.configuration).toEqual({
			objectNameField: 'name',
			objectDescriptionField: 'url',
		})
	})

	it('withholds the sort affordance from the synthesized columns', () => {
		// No row field backs Type or Strength and the server cannot sort on
		// either, so a sortable header would be a dead control that lies.
		expect(SecretList.computed.listColumnOverrides.call({})).toEqual({
			type: { sortable: false },
			strength: { sortable: false },
		})
	})

	it('resolves the Type cell label through the translated type catalogue', () => {
		useSecretTypeStore().types = [
			{ id: 'type-card', name: 'card', label: 'Payment Card' },
		]

		const label = SecretList.methods.typeLabelFor.call(
			{},
			{ id: 's-1', typeId: 'type-card' },
		)
		expect(label).toBe('Payment Card')
	})

	it('renders an empty Type label while the catalogue has not loaded', () => {
		const label = SecretList.methods.typeLabelFor.call(
			{},
			{ id: 's-1', typeId: 'type-unknown' },
		)
		expect(label).toBe('')
	})

	// The template renders the muted dash whenever this says false, so the
	// Strength column never shows blank holes between pills.
	it('shows the strength badge only for scored, unblocked rows', () => {
		useHealthStore().findings = [
			{ id: 's-scored', score: 3, flags: [] },
			// zxcvbn 0 is a real score (Very weak) — a falsy check would
			// swap the reddest badge in the column for the dash.
			{ id: 's-zero', score: 0, flags: [] },
		]
		const visible = SecretList.methods.strengthBadgeVisible

		expect(visible.call({}, { id: 's-scored' })).toBe(true)
		expect(visible.call({}, { id: 's-zero' })).toBe(true)
		expect(visible.call({}, { id: 's-unscored' })).toBe(false)
		// Blocked wins even over a stale score left behind by a suite
		// revoked mid-session — matching the list view's suppression.
		expect(visible.call({}, { id: 's-scored', blocked: true })).toBe(false)
	})
})
