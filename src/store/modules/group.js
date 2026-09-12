/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Nextcloud groups, read from the server's OWN provisioning API.
 *
 * Groups are not keepiq's data and keepiq keeps no list of them: a team folder
 * stores a group id, and who is in that group is the server's business. So the
 * candidates come from `GET /ocs/v2.php/cloud/groups`, which any logged-in user
 * may call (`GroupsController::getGroups` is `#[NoAdminRequired]`) and which
 * answers `{ groups: [ '<gid>', ... ] }`.
 *
 * This is deliberately NOT the source used for user candidates. A user has to
 * hold an encryption suite before a secret can be encrypted to them, so that
 * list comes from `share.searchShareableRecipients` — Nextcloud's sharee search
 * narrowed by the shareability probe. A group carries no key of its own — its
 * members are resolved, and key-checked, when the fan-out runs — so there is
 * nothing to filter it by here.
 *
 * The option shape IS shared with that list (`{ id, label }`), so one picker
 * can render either without knowing which it is on. A group has no display
 * name, so its label is its id.
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/**
 * How many groups one search asks for.
 *
 * The endpoint pages, and an instance can have far more groups than a picker
 * should ever render at once, so callers are expected to search rather than
 * scroll — which is why this is a page size and not a "load everything" cap.
 *
 * @type {number}
 */
export const GROUP_PAGE_SIZE = 100

export const useGroupStore = defineStore('group', {
	state: () => ({
		/**
		 * Groups from the most recent search, as picker options.
		 *
		 * @type {Array<{id: string, label: string}>}
		 */
		groups: [],
		/** @type {boolean} Whether a search is in flight. */
		loading: false,
		/**
		 * Why the last search produced nothing, when the reason was not
		 * "nobody matches". An empty picker otherwise reads the same whether
		 * the directory answered "none" or did not answer at all.
		 *
		 * @type {string|null}
		 */
		candidatesError: null,
		/**
		 * Sequence number of the most recently STARTED search.
		 *
		 * Two searches that both fire race, and the picker must show the
		 * answer to the last term typed rather than the last one to arrive: a
		 * slow "dev" landing after a fast "devops" would otherwise win.
		 *
		 * @type {number}
		 */
		searchSeq: 0,
	}),

	actions: {
		/**
		 * Search the server's groups.
		 *
		 * @param {string} [search] Substring to match; '' returns the first page.
		 *
		 * @return {Promise<Array<{id: string, label: string}>>} Options for
		 *   this call's own answer, sorted by id — whether or not a newer
		 *   search has since superseded it in the store.
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
		 */
		async fetchGroups(search = '') {
			const seq = ++this.searchSeq
			this.candidatesError = null
			this.loading = true
			try {
				const response = await axios.get(generateOcsUrl('cloud/groups'), {
					// Both are required of an OCS call and neither is added for
					// us: without the header the route answers 401 regardless of
					// the session, and without `format` it answers XML.
					headers: { 'OCS-APIRequest': 'true' },
					params: {
						format: 'json',
						search,
						limit: GROUP_PAGE_SIZE,
					},
				})

				const groups = response.data?.ocs?.data?.groups
				const options = (Array.isArray(groups) ? [...groups] : [])
					.sort()
					.map((gid) => ({ id: gid, label: gid }))

				if (seq === this.searchSeq) {
					this.groups = options
				}
				return options
			} catch (e) {
				if (seq === this.searchSeq) {
					this.candidatesError =
						e?.response?.data?.ocs?.meta?.message
						|| e?.message
						|| 'Failed to search groups'
				}
				throw e
			} finally {
				// A superseded search must not clear a flag the newer one set,
				// or the spinner disappears while that one is still running.
				if (seq === this.searchSeq) {
					this.loading = false
				}
			}
		},
	},
})
