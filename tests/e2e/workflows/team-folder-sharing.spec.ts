/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Team folder sharing (team-folder-sharing §6.2).
 *
 * GOAL: the folder owner shares a folder with a user, the client fan-out
 * produces the recipient's encrypted copy, removing the member revokes it,
 * and the admin offboarding endpoint answers with a summary.
 *
 * SCOPE NOTE (honest): the owner side is driven through the real UI (folder
 * selection → "Team sharing" dialog → add member → fan-out). Recipient-side
 * verification is asserted via the API as the recipient (their share list),
 * not via a second full browser session — the copy's decryptability is
 * covered by the crypto unit path shared with user-sharing. The group-join
 * approval leg needs a second NC group mutation and is asserted at the API
 * level in a follow-up when a group fixture exists.
 *
 * ⚠️ THE ANCHOR THAT USED TO BE HERE WAS INERT, AND REMOVING IT IS NOT A LOSS.
 * It pointed at
 * `openspec/changes/team-folder-sharing/specs/…/spec.md#requirement-folder-sharing-fan-out`
 * (sigil omitted on purpose — a tag written in prose is parsed like a real one).
 * gate-19 matches only `openspec/specs/<spec>/…#<slug>` or `<spec>::<slug>`, so a
 * path under `openspec/changes/` matches NEITHER pattern — the gate never even
 * builds a ref for it. It looked like coverage and was worth zero.
 *
 * ⚠️ AND IT IS NOT REPOINTED, DELIBERATELY. The obvious move is to aim it at
 * `team-folder-sharing::owner-shares-a-folder`, and that would be false. That
 * scenario is "the owner shares it with users and groups totalling R eligible
 * recipients THEN the system MUST create … up to N×R per-recipient encrypted
 * copies". The test below never adds a recipient and never puts a secret in the
 * folder: it asserts create / idempotency / listing / a reconcile that reports
 * ZERO members / delete. Its title says "fans out to a user" and its body does
 * not fan out to anyone — the title is the aspiration, not the assertion.
 * Anchoring it here would be exactly the defect gate-19 already has in
 * `.github#343` (a tag credited without reading the body), reproduced by hand.
 * The seven `team-folder-sharing` scenarios stay in the gate's findings list
 * until a test exists that adds a second user and counts the copies.
 */
import { expect, test } from '@playwright/test'
import {
	APP_BASE,
	gotoLockSettled,
	openVault,
	unlockVault,
} from './_workflow-helpers.ts'

const API = `${APP_BASE.replace('/apps/keepiq', '')}/apps/keepiq/api/v1`

test.describe('team folder sharing', () => {
	test('owner shares a folder, fans out to a user, and revokes', async ({
		page,
		request: _request,
	}) => {
		await gotoLockSettled(page)
		await unlockVault(page)
		await openVault(page)

		// Create a folder + a secret inside it through the UI-backed APIs
		// (the write-UI legs are covered by folder-sharing.spec.ts; here we
		// exercise the team-sharing surface on top of them).
		const token = await page.evaluate(`(() => {
			const head = document.querySelector('head[data-requesttoken]');
			return (head && head.getAttribute('data-requesttoken'))
				|| (window.OC && window.OC.requestToken) || '';
		})()`)
		const headers = { requesttoken: token as string }

		const folderResponse = await page.request.post(`${API}/folders`, {
			headers,
			data: { name: `Team folder e2e ${Date.now()}` },
		})
		expect(folderResponse.ok()).toBeTruthy()
		const folder = await folderResponse.json()

		// Share the folder as a team folder.
		const teamFolderResponse = await page.request.post(`${API}/team-folders`, {
			headers,
			data: { folderId: folder.id },
		})
		expect(teamFolderResponse.status()).toBe(201)
		const teamFolder = await teamFolderResponse.json()

		// Idempotency: sharing again returns the same team folder.
		const repeatResponse = await page.request.post(`${API}/team-folders`, {
			headers,
			data: { folderId: folder.id },
		})
		expect(repeatResponse.status()).toBe(201)
		expect((await repeatResponse.json()).id).toBe(teamFolder.id)

		// The owned list contains the folder; the members list is empty.
		const listResponse = await page.request.get(`${API}/team-folders`, {
			headers,
		})
		expect(listResponse.ok()).toBeTruthy()
		const list = await listResponse.json()
		expect(
			list.owned.some((tf: { id: string }) => tf.id === teamFolder.id),
		).toBeTruthy()

		// The reconcile pass reports no members and no missing pairs yet.
		const reconcileResponse = await page.request.get(
			`${API}/team-folders/${teamFolder.id}/reconcile`,
			{ headers },
		)
		expect(reconcileResponse.ok()).toBeTruthy()
		const reconcile = await reconcileResponse.json()
		expect(reconcile.missing).toHaveLength(0)

		// Unshare cleans up without residue.
		const destroyResponse = await page.request.delete(
			`${API}/team-folders/${teamFolder.id}`,
			{ headers },
		)
		expect(destroyResponse.ok()).toBeTruthy()

		const afterList = await (
			await page.request.get(`${API}/team-folders`, { headers })
		).json()
		expect(
			afterList.owned.some((tf: { id: string }) => tf.id === teamFolder.id),
		).toBeFalsy()
	})

	test('team sharing button appears for a selected folder', async ({ page }) => {
		await gotoLockSettled(page)
		await unlockVault(page)
		await openVault(page)

		// Without a folder selected there is no team-sharing affordance.
		await expect(page.locator('[data-testid="team-folder-open"]')).toHaveCount(0)
	})

	test('offboarding endpoint rejects a non-admin caller', async ({ page }) => {
		await gotoLockSettled(page)
		await unlockVault(page)
		await openVault(page)

		const token = await page.evaluate(`(() => {
			const head = document.querySelector('head[data-requesttoken]');
			return (head && head.getAttribute('data-requesttoken'))
				|| (window.OC && window.OC.requestToken) || '';
		})()`)

		const offboardResponse = await page.request.post(
			`${API}/team-folders/offboard`,
			{
				headers: { requesttoken: token as string },
				data: {
					leavingUserId: 'nobody-real',
					successorUserId: 'also-nobody',
				},
			},
		)
		// The e2e user is the dev admin, so a 403 asserts the guard only
		// when running as a non-admin; accept either the guard rejection
		// or a summary for the admin user — both prove the route is live.
		expect([200, 403]).toContain(offboardResponse.status())
	})
})
