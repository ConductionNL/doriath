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
 * This is deliberately NOT the shape used for user candidates. A user has to
 * hold an encryption suite before a secret can be encrypted to them, so that
 * list comes from the suites table (encryptionSuite.fetchSuiteOwners). A group
 * carries no key of its own — its members are resolved, and key-checked, when
 * the fan-out runs — so there is nothing to filter it by here.
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
		/** @type {Array<string>} Group ids from the most recent search. */
		groups: [],
		/** @type {boolean} Whether a search is in flight. */
		loading: false,
	}),

	actions: {
		/**
		 * Search the server's groups.
		 *
		 * @param {string} [search] Substring to match; '' returns the first page.
		 *
		 * @return {Promise<Array<string>>} Group ids, sorted.
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
		 */
		async fetchGroups(search = '') {
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
				this.groups = Array.isArray(groups) ? [...groups].sort() : []
				return this.groups
			} finally {
				this.loading = false
			}
		},
	},
})
