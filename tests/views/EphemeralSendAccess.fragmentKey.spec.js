/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The content-key extraction of the anonymous send access page.
 *
 * The key arrives in the URL fragment (`#k=<key>`) so it never reaches the
 * server — see ephemeralSend.createSend(). Legacy hash links carried it as
 * `?k=` INSIDE the old SPA fragment, which vue-router parsed into the route
 * query; that spelling must keep decrypting, because those links are in
 * recipients' inboxes.
 *
 * The computeds are exercised directly on the component options — mounting
 * would only add the page's mounted() peek request to what is a pure
 * function of $route.
 */

import { describe, expect, it } from 'vitest'
import EphemeralSendAccess from '../../src/views/EphemeralSendAccess.vue'

/**
 * Call the component's `fragmentKey` computed against a $route stub.
 *
 * @param {object} $route The route stub.
 * @return {string} The extracted content key.
 */
function fragmentKeyFor($route) {
	return EphemeralSendAccess.computed.fragmentKey.call({ $route })
}

describe('EphemeralSendAccess.fragmentKey', () => {
	it('reads the key from the #k= fragment (the shipped link shape)', () => {
		expect(fragmentKeyFor({ hash: '#k=a1b2-c3_d4', query: {} })).toBe(
			'a1b2-c3_d4',
		)
	})

	it('falls back to the route query for legacy hash links', () => {
		expect(fragmentKeyFor({ hash: '', query: { k: 'legacy-key' } })).toBe(
			'legacy-key',
		)
	})

	it('prefers the fragment when both are present', () => {
		expect(fragmentKeyFor({ hash: '#k=frag', query: { k: 'query' } })).toBe(
			'frag',
		)
	})

	it('yields the password path ("") when neither carries a key', () => {
		expect(fragmentKeyFor({ hash: '', query: {} })).toBe('')
		expect(fragmentKeyFor({ hash: '#section', query: {} })).toBe('')
		expect(fragmentKeyFor({ query: {} })).toBe('')
	})
})
