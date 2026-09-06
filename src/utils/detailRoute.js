/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Route ↔ detail-sidebar mapping (restyle Stage 8).
 *
 * The secret detail renders as a right-hand NcAppSidebar OVER the vault
 * list instead of on a page of its own. The open/closed state lives in
 * the URL: the list routes carry an optional `:id?` segment
 * (`/secrets/:id?`, `/folders/:folderId/:id?`), so a deep link like
 * `/secrets/<id>` renders the list WITH the sidebar open, and closing
 * the sidebar just drops the segment. Bookmarks, the browser extension
 * and the e2e specs keep their `/secrets/:id` URLs; the folder context
 * survives in the path, so opening a secret never rebuilds the list
 * behind it (CnPageRenderer keys its page render on the page id).
 */

/** The two list routes able to host the detail sidebar. */
const LIST_ROUTE_NAMES = ['SecretList', 'SecretListFolder']

/**
 * The route location that opens a secret's detail sidebar while keeping
 * the current list context (root or folder) behind it.
 *
 * @param {object} route The current $route.
 * @param {string} id The secret id to open.
 * @return {object} A vue-router location object.
 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
 */
export function secretDetailLocation(route, id) {
	if (route?.name === 'SecretListFolder' && route.params?.folderId) {
		return {
			name: 'SecretListFolder',
			params: { folderId: route.params.folderId, id },
		}
	}
	return { name: 'SecretList', params: { id } }
}

/**
 * The route location that closes the detail sidebar: the same list view
 * without the `:id` segment.
 *
 * @param {object} route The current $route.
 * @return {object} A vue-router location object.
 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
 */
export function closeDetailLocation(route) {
	if (route?.name === 'SecretListFolder' && route.params?.folderId) {
		return {
			name: 'SecretListFolder',
			params: { folderId: route.params.folderId },
		}
	}
	return { name: 'SecretList' }
}

/**
 * The secret id whose detail sidebar the current route asks for, or null.
 *
 * Only the two list routes host the sidebar — other routes with an `:id`
 * param (e.g. ApplicationDetail) must not open it.
 *
 * @param {object} route The current $route.
 * @return {string|null} The open secret's id, or null when closed.
 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
 */
export function activeDetailSecretId(route) {
	if (!route || !LIST_ROUTE_NAMES.includes(route.name)) {
		return null
	}
	return route.params?.id || null
}
