/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Folders + secret sharing.
 *
 * GOAL: create a folder, move a secret into it, and share a secret (with a user
 * or via a public link), asserting the share exists.
 *
 * HONEST STATUS (verified live 2026-06-11): all three legs are now drivable
 * through the real write-UI (implement-secrets-write-ui change):
 *
 *   - Folder create: SecretList.vue offers a "New folder" affordance that opens
 *     FolderCreateDialog (src/dialogs/), wired to folderStore.createFolder.
 *     POST /api/v1/folders persists owner_type cleanly.
 *   - Move: the detail sidebar's "Secret actions" menu offers a "Move" entry
 *     (SecretMoveDialog) that re-parents a secret via
 *     secret.updateSecret({ folderId }).
 *   - Share: the detail sidebar offers an icon-only "Share" button (SecretShareDialog)
 *     that creates a password-protected public link via linkShare.createLinkShare
 *     (Argon2id + AES-GCM snapshot, client-side) and reveals the link + password
 *     once. The token resolves via the public two-phase endpoint.
 *
 * The vault unlocks headlessly with the dev master password (the suite's cert
 * carries the matching public key, so a UI-created secret round-trips). The
 * unlockVault() helper dispatches a native button click (the themed NcButton
 * swallows Playwright's synthetic click).
 *
 * User-to-user sharing remains DEFERRED — the implement-user-sharing backend +
 * store are unbuilt, so the share dialog surfaces it only as a disabled
 * affordance; that leg is asserted as disabled, not exercised.
 *
 * ⚠️ The file-level anchor that used to be here pointed at
 * `openspec/specs/folders/spec.md#user-organises-secrets-into-folders` (sigil
 * omitted on purpose — gate-19 parses a tag written in prose exactly like a real
 * one, so quoting a broken anchor re-creates it). It was DANGLING:
 * `openspec/specs/folders/` does not exist in this repository at all.
 * gate-19 says nothing about a dangling anchor, so these four passing UI tests
 * were credited to zero scenarios. They are anchored per-test below, against the
 * `secrets-write-ui` scenarios they actually drive.
 */
import { expect, test } from '@playwright/test'
import {
	clickOverflowAction,
	gotoLockSettled,
	gotoVaultRoute,
	openVault,
	unlockVault,
} from './_workflow-helpers.ts'

const REQ_TOKEN = `(() => {
	const head = document.querySelector('head[data-requesttoken]');
	return (head && head.getAttribute('data-requesttoken'))
		|| (window.OC && window.OC.requestToken) || '';
})()`

/** Native DOM click on the first button under `selector` matching `text`. */
async function nativeClickByText(
	page,
	selector: string,
	text: string,
): Promise<void> {
	await page.evaluate(
		({ selector, text }) => {
			const b = (
				Array.from(
					document.querySelectorAll(selector),
				) as HTMLButtonElement[]
			).find((x) => new RegExp(text, 'i').test(x.textContent || ''))
			if (b) {
				b.click()
			}
		},
		{ selector, text },
	)
}

/**
 * Native DOM click on the LAST button matching `text` — used for a dialog's
 * primary action when the same label also appears on the page behind the modal
 * (e.g. the "Move" affordance on the detail view + the dialog's "Move" submit).
 */
async function nativeClickLastByText(page, text: string): Promise<void> {
	await page.evaluate((text) => {
		const bs = (
			Array.from(
				document.querySelectorAll('body button'),
			) as HTMLButtonElement[]
		).filter((x) => new RegExp(text, 'i').test(x.textContent || ''))
		const b = bs[bs.length - 1]
		if (b) {
			b.click()
		}
	}, text)
}

/** Open a non-blocked seeded secret's detail view by clicking its list row. */
async function openFirstSecret(page): Promise<string> {
	await expect(page.locator('.secret-list-item').first()).toBeVisible({
		timeout: 15_000,
	})
	const name = await page.evaluate(() => {
		const rows = Array.from(
			document.querySelectorAll('.secret-list-item'),
		) as HTMLElement[]
		// Skip blocked secrets (their detail renders a "locked" empty state, not a card).
		const row =
			rows.find((r) => !r.classList.contains('secret-list-item--blocked'))
			|| rows[0]
		// Read ONLY the element's own text nodes. `.secret-list-item__name`
		// wraps `{{ secret.name }}` AND a <StrengthBadge>, so `textContent`
		// yields "AWS Console Very strong" once the badge has resolved — a name
		// that matches nothing in the API payload this is compared against. The
		// badge loads asynchronously, so whether it is present is a race: this
		// read used to win it by accident and the test only failed when it
		// lost. Excluding child ELEMENTS makes the extraction independent of
		// that timing rather than lucky.
		const n = row
			? Array.from(
					row.querySelector('.secret-list-item__name')?.childNodes || [],
				)
					.filter((c) => c.nodeType === 3)
					.map((c) => c.textContent || '')
					.join('')
					.trim()
			: ''
		if (row) {
			const main = row.querySelector(
				'.secret-list-item__main',
			) as HTMLElement | null
			;(main || row).click()
		}
		return n
	})
	await expect(page.locator('.secret-detail__card')).toBeVisible({
		timeout: 20_000,
	})
	return name
}

test.describe('Workflow: folders + sharing — folders/spec.md', () => {
	test('the folder list API returns a well-formed (empty) tree', async ({
		page,
	}) => {
		await gotoLockSettled(page)
		const folders = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/keepiq/api/v1/folders', {
				credentials: 'include',
				headers: { requesttoken: token },
			})
			return { status: res.status, body: await res.json().catch(() => null) }
		}, REQ_TOKEN)
		expect(folders.status).toBe(200)
		expect(Array.isArray(folders.body)).toBe(true)
	})

	/*
	 * Folder create via the "New folder" dialog. The folder appears in the tree
	 * and is persisted (owner_type written, no NOT-NULL violation).
	 */
	test('create a folder via the UI dialog (appears in the tree, persists)', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::create-a-folder
		const FOLDER = `__e2e_folder_${Date.now()}`
		await unlockVault(page)
		await openVault(page)

		// The create affordance lives in the actions bar's "Actions" overflow
		// (restyle Stage 8); at the vault root its label is "New vault". The
		// helper clicks the NcActionButton's INNER button — the testid sits on
		// the presentational <li>, whose click fires nothing.
		await clickOverflowAction(page, 'open-create-folder')
		await expect(page.locator('.folder-form')).toBeVisible({ timeout: 10_000 })
		await page
			.locator('.folder-form input[type="text"]')
			.first()
			.fill(FOLDER, { force: true })
		await page.waitForTimeout(300)
		// At the vault root the dialog's submit reads "Create vault"
		// (level-appropriate terminology).
		await nativeClickByText(page, 'body button', 'Create (vault|folder)')
		await expect(page.locator('.folder-form')).toHaveCount(0, {
			timeout: 15_000,
		})

		// The new vault appears in the app nav's folder tree (restyle Stage 7)
		// — the create dialog's onSaved refetches the shared folder store.
		await expect(
			page.locator('[data-testid="nav-folder-tree"]').getByText(FOLDER),
		).toBeVisible({ timeout: 15_000 })

		const folder = await page.evaluate(
			async ({ tokExpr, name }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const res = await fetch('/index.php/apps/keepiq/api/v1/folders', {
					credentials: 'include',
					headers: { requesttoken: token },
				})
				const body = await res.json()
				return (
					(Array.isArray(body) ? body : []).find((f) => f.name === name)
					|| null
				)
			},
			{ tokExpr: REQ_TOKEN, name: FOLDER },
		)
		expect(folder, 'folder must be persisted').toBeTruthy()

		// The root shows no vault rows (2026-09-03): the nav's folder tree is
		// the one place a new vault appears.
		await expect(page.getByTestId(`nav-folder-${folder.id}`)).toBeVisible({
			timeout: 10_000,
		})

		await page.evaluate(
			async ({ tokExpr, id }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				await fetch(`/index.php/apps/keepiq/api/v1/folders/${id}`, {
					method: 'DELETE',
					credentials: 'include',
					headers: { requesttoken: token },
				})
			},
			{ tokExpr: REQ_TOKEN, id: folder.id },
		)
	})

	/*
	 * Move a secret into a folder via the "Move" dialog and assert its folderId
	 * persisted. Creates a throwaway folder, moves the first seeded secret into
	 * it, asserts, then restores the secret to the vault root and deletes the
	 * folder.
	 */
	test('move a secret into a folder via the UI (folderId persisted)', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::move-a-secret-into-a-folder
		const FOLDER = `__e2e_movefolder_${Date.now()}`
		await unlockVault(page)
		await openVault(page)

		const folder = await page.evaluate(
			async ({ tokExpr, name }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const res = await fetch('/index.php/apps/keepiq/api/v1/folders', {
					method: 'POST',
					credentials: 'include',
					headers: {
						requesttoken: token,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ name }),
				})
				return res.json()
			},
			{ tokExpr: REQ_TOKEN, name: FOLDER },
		)
		expect(folder.id).toBeTruthy()

		// Reload so the new folder is in the move dialog's options.
		// ADR-074 rule 4: `networkidle` cannot settle on Nextcloud. The reload
		// drops the in-memory key, so `unlockVault()` below has to wait for the
		// lock screen anyway — that is the readiness signal.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await unlockVault(page)
		await openVault(page)

		const secretName = await openFirstSecret(page)
		expect(secretName).toBeTruthy()

		// Restyle Stage-8 polish: Move lives in the sidebar's "Secret
		// actions" ("…") menu. Native clicks — the themed buttons swallow
		// Playwright's synthetic click, same as elsewhere in this file.
		await page
			.getByRole('button', { name: /Secret actions/i })
			.evaluate((el: HTMLElement) => el.click())
		// TARGET THE MENUITEM, NOT A NODE GUESSED FROM THE MARKUP.
		//
		// This used to descend from `data-testid=secret-detail-move` to an
		// inner <button> and dispatch `el.click()`, on the assumption that
		// the testid lands on NcActionButton's <li> root while the handler
		// sits on the button. That assumption is what broke: after the
		// Stage-8 restyle the synthetic dispatch stopped reaching the
		// handler, and the failure was invisible — the trace shows the
		// testid RESOLVING in 0.1s and then `.move-form` timing out for 10s,
		// with the menu still `[expanded]` and `menuitem "Move"` present in
		// the snapshot. A click that lands on the wrong node looks exactly
		// like a dialog that refuses to open.
		//
		// The accessibility tree exposes this as `menuitem "Move"` whatever
		// element NcActionButton happens to render, so ask for that and let
		// Playwright's own click do the actionability checks. It is also a
		// real click rather than a dispatched event, which is what a user
		// performs.
		await page.getByRole('menuitem', { name: 'Move' }).click()
		await expect(page.locator('.move-form')).toBeVisible({ timeout: 10_000 })
		await page.locator('.move-form .vs__dropdown-toggle').click()

		// Is the option list actually REACHABLE, not merely present? This block
		// exists because the clicks below kept passing while a human could not
		// use the control at all: with the teleport turned off, the open list
		// was clipped by `.dialog__content` (52px tall, `overflow: auto`) to
		// roughly 9 visible pixels of 350. Playwright scrolls an element into
		// view before clicking, so it drove the select happily — the failure
		// was invisible to exactly the test that covered the flow.
		//
		// The list teleports again now, so it floats over the dialog and the
		// question is whether it is on screen rather than whether some scroll
		// box contains it. Measure against the VIEWPORT and against any
		// clipping ancestor: a teleported list has none, an inline one is cut
		// down to the sliver this test exists to catch.
		const listVisibility = await page.evaluate(() => {
			const menu = document.querySelector('.vs__dropdown-menu')
			if (!menu) return null
			const m = menu.getBoundingClientRect()
			let top = Math.max(m.top, 0)
			let bottom = Math.min(m.bottom, window.innerHeight)
			for (
				let node = menu.parentElement;
				node && node !== document.body;
				node = node.parentElement
			) {
				const style = getComputedStyle(node)
				if (style.overflowY === 'auto' || style.overflowY === 'scroll') {
					const box = node.getBoundingClientRect()
					top = Math.max(top, box.top)
					bottom = Math.min(bottom, box.bottom)
				}
			}
			return {
				menuHeight: m.height,
				visibleHeight: Math.max(0, bottom - top),
			}
		})
		expect(
			listVisibility,
			'the destination-folder list should be rendered',
		).not.toBeNull()
		expect(
			listVisibility!.visibleHeight,
			`destination-folder list clipped: only ${listVisibility!.visibleHeight}px `
				+ `of ${listVisibility!.menuHeight}px is inside the dialog's visible area`,
		).toBeGreaterThan(120)

		await page
			.locator('.vs__dropdown-menu li', { hasText: FOLDER })
			.first()
			.click()
		await page.waitForTimeout(300)
		// The detail view's "Move" affordance is still in the DOM behind the modal;
		// click the LAST "Move" button (the dialog's submit action).
		await nativeClickLastByText(page, 'Move')
		await expect(page.locator('.move-form')).toHaveCount(0, { timeout: 15_000 })

		const moved = await page.evaluate(
			async ({ tokExpr, name }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const res = await fetch(
					'/index.php/apps/keepiq/api/v1/secrets?limit=200',
					{
						credentials: 'include',
						headers: { requesttoken: token },
					},
				)
				const body = await res.json()
				return (body.items || []).find((s) => s.name === name) || null
			},
			{ tokExpr: REQ_TOKEN, name: secretName },
		)
		expect(moved, 'moved secret must exist').toBeTruthy()
		expect(moved.folderId).toBe(folder.id)

		// The scenario's SECOND clause: "the secret MUST appear under that
		// folder's filter in the vault list". A persisted `folderId` is the
		// write; this is the read, and they are not the same claim — the list
		// could filter on something else entirely and the row would vanish.
		// Navigate in place (a reload would drop the in-memory key).
		await gotoVaultRoute(page, `folders/${folder.id}`)
		await expect(
			page.locator('.secret-list-item', { hasText: secretName }),
			`"${secretName}" is not listed under the folder it was moved into`,
		).toBeVisible({ timeout: 20_000 })

		await page.evaluate(
			async ({ tokExpr, secretApiId, folderId }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				await fetch(`/index.php/apps/keepiq/api/v1/secrets/${secretApiId}`, {
					method: 'PUT',
					credentials: 'include',
					headers: {
						requesttoken: token,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ folderId: null }),
				})
				await fetch(`/index.php/apps/keepiq/api/v1/folders/${folderId}`, {
					method: 'DELETE',
					credentials: 'include',
					headers: { requesttoken: token },
				})
			},
			{ tokExpr: REQ_TOKEN, secretApiId: moved.id, folderId: folder.id },
		)
	})

	/*
	 * Share a secret via a public link through the "Share" dialog: the browser
	 * decrypts the secret, generates a one-time password, Argon2id+AES encrypts
	 * the snapshot, and POSTs only the blob. Assert the link + password are shown
	 * once and the token resolves via the public two-phase endpoint.
	 */
	test('share a secret via a public link (link shown once, token resolves)', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::create-a-public-link-share
		await unlockVault(page)
		await openVault(page)
		await openFirstSecret(page)

		await page
			.getByTestId('secret-detail-share')
			.evaluate((el: HTMLElement) => el.click())
		await expect(page.locator('.share-dialog')).toBeVisible({ timeout: 10_000 })
		await nativeClickByText(page, 'body button', 'Create link')

		// The one-time reveal shows the link URL and password.
		await expect(page.locator('.share-dialog__reveal')).toBeVisible({
			timeout: 15_000,
		})
		const revealed = page.locator('.share-dialog__reveal .share-dialog__value')
		const linkUrl = (await revealed.first().textContent())?.trim() || ''
		expect(linkUrl).toMatch(/\/share\/link\/|link-shares|token=/i)
		// The scenario says the dialog MUST show the link URL *and* the one-time
		// password, "each with a copy control". Two revealed values and two copy
		// controls — asserting only the URL would pass on a dialog that never
		// showed the password the recipient needs.
		await expect(revealed).toHaveCount(2)
		const generatedPassword = (await revealed.nth(1).textContent())?.trim() || ''
		expect(
			generatedPassword.length,
			'no one-time link password was revealed',
		).toBeGreaterThan(7)
		await expect(
			page.locator(
				'.share-dialog__reveal button, .share-dialog__reveal .copy-button',
			),
		).toHaveCount(2)

		// The created share's token must resolve via the PUBLIC two-phase endpoint.
		const tokenResolves = await page.evaluate(
			async ({ tokExpr }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const secrets = await (
					await fetch('/index.php/apps/keepiq/api/v1/secrets?limit=200', {
						credentials: 'include',
						headers: { requesttoken: token },
					})
				).json()
				for (const s of secrets.items || []) {
					const shares = await (
						await fetch(
							`/index.php/apps/keepiq/api/v1/secrets/${s.id}/link-shares`,
							{
								credentials: 'include',
								headers: { requesttoken: token },
							},
						)
					).json()
					if (Array.isArray(shares) && shares.length > 0) {
						const tok =
							shares[0].token
							|| (shares[0].linkUrl || '').split('/').pop()
						if (tok) {
							const res = await fetch(
								`/index.php/apps/keepiq/api/v1/public/link-shares/${tok}`,
								{
									credentials: 'include',
									headers: { requesttoken: token },
								},
							)
							return { found: true, status: res.status }
						}
					}
				}
				return { found: false, status: 0 }
			},
			{ tokExpr: REQ_TOKEN },
		)
		expect(tokenResolves.found, 'a link share must exist').toBe(true)
		expect([200, 401, 403]).toContain(tokenResolves.status)

		// Cleanup: revoke all link shares so re-runs stay idempotent.
		await page.evaluate(
			async ({ tokExpr }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const secrets = await (
					await fetch('/index.php/apps/keepiq/api/v1/secrets?limit=200', {
						credentials: 'include',
						headers: { requesttoken: token },
					})
				).json()
				for (const s of secrets.items || []) {
					const shares = await (
						await fetch(
							`/index.php/apps/keepiq/api/v1/secrets/${s.id}/link-shares`,
							{
								credentials: 'include',
								headers: { requesttoken: token },
							},
						)
					).json()
					for (const sh of Array.isArray(shares) ? shares : []) {
						await fetch(
							`/index.php/apps/keepiq/api/v1/link-shares/${sh.id}`,
							{
								method: 'DELETE',
								credentials: 'include',
								headers: { requesttoken: token },
							},
						)
					}
				}
			},
			{ tokExpr: REQ_TOKEN },
		)
	})

	/*
	 * User-to-user sharing is DEFERRED (implement-user-sharing backend unbuilt).
	 * The Share dialog surfaces it as a DISABLED affordance; assert it is present
	 * and disabled rather than exercising a flow that has no backing store.
	 */
	test('share-with-a-user affordance is present but disabled (deferred backend)', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::user-to-user-sharing-is-deferred
		await unlockVault(page)
		await openVault(page)
		await openFirstSecret(page)
		await page
			.getByTestId('secret-detail-share')
			.evaluate((el: HTMLElement) => el.click())
		await expect(page.locator('.share-dialog')).toBeVisible({ timeout: 10_000 })
		const userBtn = page.locator('.share-dialog__user button')
		await expect(userBtn).toBeVisible()
		await expect(userBtn).toBeDisabled()
		await expect(userBtn).toContainText(/coming soon/i)

		// "…MUST NOT issue any request until the implement-user-sharing backend
		// exists." A disabled attribute is a claim about the markup; this is the
		// claim about the wire. Record every user-share request the page makes
		// while the affordance is clicked, and require none.
		const userShareRequests: string[] = []
		const listener = (r: { url: () => string }) => {
			if (
				/\/api\/v1\/(secrets\/[^/]+\/)?(user-)?shares?(\b|\/|\?)/.test(
					r.url(),
				)
				&& !/link-shares/.test(r.url())
			) {
				userShareRequests.push(r.url())
			}
		}
		page.on('request', listener)
		// A disabled button swallows a normal click, so dispatch a native one —
		// the same trick the themed-NcButton helpers use. If the handler is
		// wired up anyway, this is what would catch it.
		await page.evaluate(() => {
			const b = document.querySelector(
				'.share-dialog__user button',
			) as HTMLButtonElement | null
			b?.click()
		})
		await page.waitForTimeout(2_000)
		page.off('request', listener)
		expect(
			userShareRequests,
			'the deferred user-share affordance issued a request',
		).toEqual([])
	})

	/*
	 * Deep folder navigation purely through the LIST (restyle Stage 6): the
	 * vault root shows top-level vaults as rows, clicking one descends, the
	 * breadcrumb trail appears inside a folder, and clicking a crumb walks
	 * back up — no sidebar involvement.
	 */
	test('navigates root → vault → nested folder via subfolder rows and breadcrumbs', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::create-a-folder
		const PARENT = `__e2e_nav_vault_${Date.now()}`
		const CHILD = `__e2e_nav_sub_${Date.now()}`
		await unlockVault(page)
		await openVault(page)

		// Seed a vault + nested folder via the API (the create dialog is
		// covered by its own test above; this one is about navigation).
		const ids = await page.evaluate(
			async ({ tokExpr, parent, child }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const mk = async (name: string, parentId: string | null) => {
					const res = await fetch(
						'/index.php/apps/keepiq/api/v1/folders',
						{
							method: 'POST',
							credentials: 'include',
							headers: {
								requesttoken: token,
								'Content-Type': 'application/json',
							},
							body: JSON.stringify({ name, parentId }),
						},
					)
					return (await res.json()).id as string
				}
				const parentId = await mk(parent, null)
				const childId = await mk(child, parentId)
				return { parentId, childId }
			},
			{ tokExpr: REQ_TOKEN, parent: PARENT, child: CHILD },
		)

		// The freshly created folders only exist server-side, and the folder
		// store refetches on unlock. A reload alone cannot surface them: it
		// wipes the in-memory CryptoKey, so the app lands back on the lock
		// gate and no vault row ever renders. Reload, unlock again, reopen
		// the vault (domcontentloaded, not networkidle — ADR-074 rule 4).
		await page.reload({ waitUntil: 'domcontentloaded' })
		await unlockVault(page)
		await openVault(page)

		// The root shows no vault rows (2026-09-03): descend into the vault
		// through the nav's folder tree, like a user would. The testid sits
		// on NcAppNavigationItem's <li>; the router link is the entry-link
		// anchor inside — `.first()` skips the expanded children's links.
		const parentNavItem = page.getByTestId(`nav-folder-${ids.parentId}`)
		await expect(parentNavItem).toBeVisible({ timeout: 20_000 })
		await parentNavItem
			.locator('a.app-navigation-entry-link')
			.first()
			.evaluate((el: HTMLElement) => el.click())

		// Inside the vault: breadcrumbs render, the nested folder is a row.
		await expect(page.getByTestId('cn-breadcrumbs')).toBeVisible({
			timeout: 20_000,
		})
		const childRow = page.getByTestId(`folder-row-${ids.childId}`)
		await expect(childRow).toBeVisible({ timeout: 20_000 })
		await childRow.evaluate((el: HTMLElement) => el.click())

		// Inside the nested folder: the trail carries the parent as a LINK;
		// clicking it walks back up to the vault.
		const crumbs = page.getByTestId('cn-breadcrumbs')
		await expect(crumbs).toContainText(CHILD, { timeout: 20_000 })
		await crumbs
			.getByText(PARENT)
			.first()
			.evaluate((el: HTMLElement) => el.click())
		await expect(page.getByTestId(`folder-row-${ids.childId}`)).toBeVisible({
			timeout: 20_000,
		})
	})
})
