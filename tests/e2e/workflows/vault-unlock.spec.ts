/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Vault unlock (high value).
 *
 * Drives the master-password lock screen and asserts the parts of the unlock
 * surface that genuinely work, while honestly flagging the parts that don't.
 *
 * LIVE FINDINGS (verified 2026-06-10 — these are the high-value coverage facts):
 *
 *   1. The instance is NOT in setup mode. The `SeedDevelopmentData` install
 *      repair step provisioned an active EncryptionSuite for admin, so the lock
 *      screen renders the UNLOCK form (one password field, "Unlock Keepiq"),
 *      never the first-time setup form.
 *
 *   2. The seeded suite CANNOT be unlocked headlessly — and, in fact, not at
 *      all. The suite's AES-GCM private-key envelope was written by the PHP
 *      EncryptService with the dev master password `Oj`, but the browser's
 *      decryptPrivateKey() (PBKDF2-SHA256 600k → AES-256-GCM, envelope v1)
 *      rejects it with OperationError for `Oj` AND for any other password.
 *      i.e. the PHP-written envelope is INCOMPATIBLE with the JS envelope
 *      format, so no master password unlocks the seeded vault. Captured as
 *      test.fixme (bug #5 in the report).
 *
 *   3. A failed unlock does NOT surface an error to the UI. Entering a wrong
 *      (or the dev) password — which provably fails to decrypt — leaves the
 *      lock screen with NO "Wrong master password or decryption failed" note,
 *      even after >12s. handleUnlock()'s catch block sets `this.error`, but the
 *      note never renders. Captured as test.fixme (bug #6).
 *
 * The data-INDEPENDENT lock-screen surface (render + route gating + button
 * enable/disable) IS solid and is asserted as the real green coverage here.
 *
 * @e2e openspec/specs/encryption-suites/spec.md#user-views-lock-screen
 */
import { expect, test } from '@playwright/test'
import manifest from '../../../src/manifest.json'
import {
	APP_BASE,
	gotoLockSettled,
	lockHeading,
	unlockVault,
} from './_workflow-helpers.ts'

test.describe('Workflow: vault unlock — encryption-suites/spec.md', () => {
	test('lock screen renders in UNLOCK mode (admin owns a seeded active suite)', async ({
		page,
	}) => {
		const heading = await gotoLockSettled(page)
		expect(heading).toMatch(/Unlock Keepiq/i)
		// Unlock mode has exactly one password field (setup mode has two).
		expect(
			await page.locator('.lock-screen input[type="password"]').count(),
		).toBe(1)
		await expect(page.locator('.lock-screen__card')).toBeVisible()
	})

	test('the Unlock button is gated on a non-empty password', async ({ page }) => {
		await gotoLockSettled(page)
		const btn = page
			.locator('.lock-screen button')
			.filter({ hasText: /^\s*Unlock\s*$/i })
			.first()
		// Disabled with an empty field…
		await expect(btn).toBeDisabled()
		// …enabled once the master-password field has a value.
		await page
			.locator('.lock-screen input[type="password"]')
			.first()
			.fill('anything', { force: true })
		await expect(btn).toBeEnabled()
	})

	test('all secret routes redirect to the lock screen while locked (zero-knowledge gate)', async ({
		page,
	}) => {
		for (const route of ['secrets', 'secrets/some-id', 'folders/some-folder']) {
			// ADR-074 rule 4: `networkidle` cannot settle on Nextcloud, and this
			// loop pays the cost three times. The lock heading is the readiness
			// signal.
			await page.goto(`/index.php/apps/keepiq/${route}`, {
				waitUntil: 'domcontentloaded',
			})
			await expect(lockHeading(page)).toBeVisible({ timeout: 20_000 })
			await expect(lockHeading(page)).toHaveText(
				/Unlock Keepiq|Set up your master password/i,
			)
			// No unlocked content leaks through the guard.
			await expect(page.locator('.secret-detail__card')).toHaveCount(0)
		}
	})

	/*
	 * Regression guard for the "lock screen is a redirect, not a gate" bug.
	 *
	 * The test above asserts the EVENTUAL state, which is why it stayed green
	 * while the gate was broken: the lock redirect used to fire from App.vue's
	 * `created()` hook, behind `await initializeStores()`. By the time it
	 * landed, CnPageRenderer had already mounted the target page and that
	 * page's `mounted()` had already issued its fetches. Playwright's
	 * networkidle + 20s heading wait sailed straight past the leak.
	 *
	 * Secret `name` / `url` / folder placement are plaintext server-side by
	 * design (searchable for the owner — see lib/Db/Secret.php), so those
	 * fetches put the real vault inventory on the wire and on screen before
	 * the lock screen replaced it.
	 *
	 * The invariant that actually catches it: while locked, no secret-bearing
	 * endpoint is requested AT ALL. Asserted on the wire, not on the DOM.
	 *
	 * @e2e openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
	 */
	test("a locked vault issues no Keepiq API request beyond the lock screen's own", async ({
		page,
	}) => {
		const leaked: string[] = []

		/*
		 * An ALLOWLIST, not a denylist. A denylist of secret-bearing endpoints
		 * only ever covers the families someone thought to name: the earlier
		 * form matched secrets/folders/shares/group-shares/link-shares and
		 * dashboard/summary, leaving applications, certificates,
		 * emergency-access, secret-requests, sends, honey/alerts, secret-types,
		 * delegations, versions, attachments, audit, leases, team-folders,
		 * export, gdpr and offline/manifest — the last being a full inventory
		 * snapshot — entirely unwatched. A new page shipping a `mounted()` fetch
		 * would have been invisible to it.
		 *
		 * Inverted, the assertion fails closed: every request to the app's API
		 * is a leak unless it is something the LOCK SCREEN itself legitimately
		 * needs. Adding an endpoint to this list is then a deliberate act with a
		 * reason, which is the property we want.
		 *
		 * Each entry below must name traffic that actually fires while locked —
		 * an entry that cannot fire is pure masking surface, since it would
		 * silently swallow an unexpected request to that family. The
		 * recipient-facing `/api/v1/public/` prefix was one such entry: the hash
		 * loop below excludes every public route, so nothing under it is
		 * reachable here and a request to it would be worth failing on.
		 */
		const LOCK_SCREEN_LEGITIMATE = [
			/\/api\/v1\/suites\b/, // suite state: setup vs unlock mode
			/\/api\/v1\/migrations\/status\b/, // resume banner
			// App.vue's created() calls initializeStores() unconditionally,
			// before any route resolves and regardless of lock state, and
			// settingsStore.fetchSettings() requests the BARE /api/settings
			// path. A `/api/settings/user\b` pattern does not cover it — the
			// `/user` segment has to be optional.
			/\/api\/settings(\/user)?(\/|$|\?)/,
			// LockScreen's own created() offers passkey unlock whenever
			// online, which is true in headless Chromium over the instance's
			// HTTPS origin.
			/\/api\/v1\/passkeys\/login-options\b/,
			// CnAppRoot's setup() calls useSupportDialog(appId, { persistence:
			// 'server' }) unconditionally — same shell layer, and for the same
			// reason, as the /api/settings entry above: it runs before any route
			// resolves and therefore regardless of lock state. The endpoint is
			// OpenRegister's AppHost GenericPreferencesController, which reads a
			// single `pref_`-namespaced UI flag for the SESSION user only (no id
			// or userId input, 401 when anonymous) and returns `{value}`. It
			// carries no vault material, so it is chrome, not a leak — which is
			// the distinction this allowlist exists to draw. Opting out with
			// `:support-dialog="false"` would silence it by removing a feature
			// rather than by judging the traffic.
			/\/api\/preferences\/support-dialog-seen\b/,
			// NOTE: `walkthrough_completed_version` is deliberately NOT listed.
			// It was added here in #488 when the walkthrough merge put that
			// probe on the wire behind the lock screen — but #486 had already
			// fixed the cause the better way, by withholding `walkthrough` from
			// the manifest while locked (`manifestForLockState`), so
			// CnAppRoot never resolves the preference at all. The two landed
			// minutes apart and the allowlist entry became an entry that CANNOT
			// FIRE, which the note above names as pure masking surface: it would
			// silently swallow a real regression of exactly this shape.
		]

		page.on('request', (req) => {
			const url = req.url()
			if (!/\/apps\/keepiq\/api\//.test(url)) {
				return
			}
			if (LOCK_SCREEN_LEGITIMATE.some((re) => re.test(url))) {
				return
			}
			leaked.push(`${req.method()} ${url}`)
		})

		/*
		 * Routes derived from the manifest rather than hand-listed, so a page
		 * added later is covered without anyone remembering to extend this test.
		 * Public (recipient-facing) routes and the lock screen itself are
		 * excluded — they are reachable while locked by design.
		 */
		const publicRoutes = [
			'SecretRequestFill',
			'LinkShareAccess',
			'EphemeralSendAccess',
			'Lock',
		]
		const hashes = (manifest.pages as Array<{ id: string; route: string }>)
			.filter((pg) => !publicRoutes.includes(pg.id))
			// Give any `:param` segment a concrete value; a deep link naming the
			// route directly is the real attack shape.
			.map((pg) => `#${pg.route.replace(/:[^/]+/g, 'some-id')}`)

		expect(hashes.length).toBeGreaterThan(5)

		for (const hash of hashes) {
			// ADR-074 rule 4, and the note above: `networkidle` never settles on
			// Nextcloud, and it was never what caught this bug — the `request`
			// listener above is attached before navigation and records every
			// request regardless. The lock heading is the readiness signal.
			await page.goto(`${APP_BASE}/${hash}`, { waitUntil: 'domcontentloaded' })
			await expect(lockHeading(page)).toBeVisible({ timeout: 20_000 })
		}

		// A leak firing just after the last lock heading painted would otherwise
		// be missed on the final iteration, since the assertion ran immediately.
		await page.waitForTimeout(1500)

		expect(
			leaked,
			`locked vault requested Keepiq API endpoints:\n${leaked.join('\n')}`,
		).toEqual([])
	})

	/*
	 * FIXED — the seeded private-key envelope is JS-compatible and the suite
	 * certificate carries the matching public key, so decryptPrivateKey() +
	 * importPrivateKey() unlock the seeded suite with `Oj`. The Unlock button is
	 * clicked natively because the themed NcButton swallows Playwright's synthetic
	 * click (the earlier "router push is a no-op" diagnosis was actually the
	 * swallowed click — the navigation fires correctly).
	 */
	test('correct dev master password unlocks the seeded vault', async ({
		page,
	}) => {
		await unlockVault(page)
		await expect(page).not.toHaveURL(/\/lock(\?|$)/, { timeout: 15_000 })
		await expect(page.locator('.lock-screen')).toHaveCount(0)
	})

	/*
	 * FIXED (was BUG #6) — a failed unlock surfaces an error note. The prior
	 * "no error renders" was the swallowed synthetic click (handleUnlock never
	 * ran). With a native click, handleUnlock's catch sets `this.error` and the
	 * note renders, and the user stays on the lock screen.
	 */
	test('wrong master password shows an error note and stays on the lock screen', async ({
		page,
	}) => {
		await gotoLockSettled(page)
		await page
			.locator('.lock-screen input[type="password"]')
			.first()
			.fill('definitely-not-the-master-pw', { force: true })
		await page.waitForTimeout(300)
		await page.evaluate(() => {
			const b = Array.from(
				document.querySelectorAll('.lock-screen button'),
			).find((x) => /Unlock/i.test(x.textContent || ''))
			if (b) {
				;(b as HTMLElement).click()
			}
		})
		await expect(
			page
				.locator('.lock-screen')
				.getByText(/Wrong master password|decryption failed/i),
		).toBeVisible({ timeout: 15_000 })
		await expect(lockHeading(page)).toHaveText(/Unlock Keepiq/i)
	})

	/*
	 * FIXED (was BUG #7) — a successful unlock navigates into the vault. The prior
	 * "router push is a no-op" was a test artifact: Playwright's synthetic click on
	 * the themed Unlock button was swallowed, so handleUnlock never ran. With a
	 * native click the unlock fires, `$router.push(returnUrl)` navigates to the
	 * Dashboard, and the lock screen unmounts.
	 */
	test('successful unlock navigates into the vault (router push fires)', async ({
		page,
	}) => {
		await unlockVault(page)
		// History router: after a successful unlock the router pushes off the
		// `/lock` gate to the return route (default `/` dashboard). Assert we
		// LEFT the gate rather than naming the destination, which varies with
		// the returnUrl the gate captured.
		await expect(page).not.toHaveURL(/\/apps\/keepiq\/lock(\?|$)/, {
			timeout: 15_000,
		})
		await expect(page.locator('.lock-screen')).toHaveCount(0)
	})

	/*
	 * SETUP MODE not drivable — an active suite already exists for admin, so the
	 * first-time setup form never renders. Driving it would require a suite-less
	 * account (no UI to revoke/delete the admin suite, and doing it out-of-band
	 * would break the other specs). The setup form's structure + 12-char strength
	 * gating is already covered by the spec-coverage lock-screen spec.
	 */
	test('first-time setup creates a suite and unlocks', async () => {
		test.fixme(
			true,
			"SETUP MODE is not drivable: an active suite already exists for admin, so the first-time setup form never renders. Driving it needs a suite-less account — there is no UI to revoke or delete the admin suite, and doing it out-of-band would break the other specs in this file. The setup form's structure and its 12-character strength gating are already covered by the spec-coverage lock-screen spec.",
		)
	})
})
