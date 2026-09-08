/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Recipient discovery in `useShareStore` (`src/store/modules/share.js`).
 *
 * A share dialog has to answer "who can I share this with", and the server
 * answers it in two halves ON PURPOSE. Nextcloud's sharee search says who the
 * caller may share with at all; keepiq's batch probe says which of those hold
 * an active suite. There is deliberately no endpoint listing suite holders —
 * a certificate is a public key and safe to hand out, but "who has a keepiq
 * vault" is a membership fact gated by no sharing permission
 * (ShareController::recipientCertificates).
 *
 * So the mistakes worth pinning are all about the seam between the two:
 * probing ids nobody asked for, keeping the ones that came back non-shareable,
 * or exceeding the server's own bound and turning a full directory into a 400
 * that reads like "nobody is shareable".
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useShareStore } from '../../src/store/modules/share.js'

/** A sharee-search row, in the shape the OCS endpoint returns. */
function sharee(userId) {
	return { label: userId, value: { shareType: 0, shareWith: userId } }
}

/** Answer the sharee search with `exact` + `wide` matches. */
function mockSharees({ exact = [], users = [] }) {
	return vi.spyOn(axios, 'get').mockResolvedValue({
		data: {
			ocs: {
				data: {
					exact: { users: exact.map(sharee) },
					users: users.map(sharee),
				},
			},
		},
	})
}

describe('useShareStore — recipient candidates', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('probes exactly the ids the sharee search named, exact matches first', async () => {
		const get = mockSharees({ exact: ['carol'], users: ['carolyn', 'carol'] })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				recipients: [
					{ userId: 'carol', shareable: true, certificate: 'PEM' },
					{
						userId: 'carolyn',
						shareable: false,
						reason: 'no_active_suite',
					},
				],
			},
		})

		const store = useShareStore()
		const candidates = await store.searchShareableRecipients('car')

		// The search is Nextcloud's own, users only, and not the global address
		// book — a lookup answers with users this server holds no suite for.
		const [url, config] = get.mock.calls[0]
		expect(url).toContain('apps/files_sharing/api/v1/sharees')
		expect(config.headers['OCS-APIRequest']).toBe('true')
		expect(config.params).toMatchObject({
			format: 'json',
			search: 'car',
			itemType: 'file',
			shareType: 0,
			lookup: false,
		})

		// Exact match first, and the duplicate between the two result sets
		// collapses: the probe is capped, so a wasted slot is a lost candidate.
		expect(post.mock.calls[0][0]).toContain('shares/recipient-certificates')
		expect(post.mock.calls[0][1]).toEqual({ userIds: ['carol', 'carolyn'] })

		// Only the shareable ones survive. The endpoint's `reason` is not
		// carried up: it says no_active_suite both for a user without a suite
		// and for one that does not exist — deliberately, so that it cannot be
		// read as a user-existence oracle — so it can say nothing beyond "not
		// this one".
		expect(candidates).toEqual(['carol'])
		expect(store.shareableRecipients).toEqual(candidates)
		expect(store.candidatesLoading).toBe(false)
	})

	it('never probes more ids than the server accepts', async () => {
		// The bound is the server's (MAX_RECIPIENT_PROBE = 100). An instance
		// whose autocomplete limit is higher would otherwise produce a 400 for
		// the whole page, which the picker cannot tell apart from "nobody here
		// can receive secrets".
		mockSharees({
			users: Array.from({ length: 150 }, (_unused, i) => `user-${i}`),
		})
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { recipients: [] } })

		await useShareStore().searchShareableRecipients('user')

		expect(post.mock.calls[0][1].userIds).toHaveLength(100)
	})

	it('does not probe at all when the search found nobody', async () => {
		// A legitimate answer: sharing restricted to group members, a term
		// under the instance's minimum length, or simply no match.
		mockSharees({})
		const post = vi.spyOn(axios, 'post')

		await expect(
			useShareStore().searchShareableRecipients('nobody'),
		).resolves.toEqual([])

		// An empty probe is a 400, and asking for it would turn "no matches"
		// into an error the caller has to explain away.
		expect(post).not.toHaveBeenCalled()
	})

	it('clears the loading flag when either half fails', async () => {
		mockSharees({ users: ['carol'] })
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('boom'))

		const store = useShareStore()
		await expect(store.searchShareableRecipients('c')).rejects.toThrow('boom')

		// The dialog swallows this error; a stuck spinner would be the only
		// trace left of it.
		expect(store.candidatesLoading).toBe(false)
	})
})
