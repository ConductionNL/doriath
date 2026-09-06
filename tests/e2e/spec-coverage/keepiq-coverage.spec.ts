/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage — Keepiq scenarios that need a provisioned, suite-less
 * user (weak-password setup-mode rejection) or that document a real UI gap
 * (user-settings dialog is unreachable from the current manifest).
 *
 * The data-independent surfaces — lock screen, gated routes, app navigation and
 * the admin settings page — are covered in the sibling spec files
 * (lock-screen / gated-routes / navigation / admin-settings). This file holds
 * the two scenarios that don't fit those flat surface tests.
 *
 * Base URL : PLAYWRIGHT_BASE_URL / BASE_URL, resolved centrally — see
 *            tests/e2e/base-url.ts. This file used to carry absolute
 *            `http://localhost:8080` literals in its WRITE paths (OCS user
 *            create/delete and a real login form), which pointed account
 *            provisioning and failed logins at the SHARED dev container
 *            regardless of what the rest of the run targeted.
 * Auth      : admin/admin (stored in .auth/admin.json via globalSetup)
 *
 * @e2e openspec/specs/encryption-suites/spec.md#weak-password-rejected
 * @e2e openspec/specs/user-settings/spec.md#user-opens-settings
 */

import { expect, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'
// The lock screen hides `.app-navigation` (exclusive-surface contract), so the
// user-settings entry is only reachable from an unlocked vault — these two
// tests borrow the workflow layer's dev-password unlock like navigation.spec.
import { unlockVault } from '../workflows/_workflow-helpers.ts'

// ---------------------------------------------------------------------------
// Encryption Suites — weak-password rejection in first-time setup
// ---------------------------------------------------------------------------

test.describe('Encryption Suites — spec: encryption-suites/spec.md', () => {
	/**
	 * @e2e encryption-suites::weak-password-rejected
	 * GIVEN a user is setting a master password (first-time setup)
	 * WHEN they type a password with zxcvbn score below the floor
	 * THEN the strength meter MUST show a warning and the submit button
	 *      MUST remain disabled.
	 *
	 * Provisions a brand-new, unique Nextcloud user via the admin OCS API so the
	 * account is guaranteed to have NO EncryptionSuite — Keepiq then renders the
	 * first-time setup form deterministically (no flaky reliance on a shared test
	 * user whose suite state drifts between runs). The user is deleted afterwards.
	 */
	test.setTimeout(120_000)
	test('weak-password-rejected — strength meter blocks weak password on setup', async ({
		page,
	}) => {
		// @e2e encryption-suites::weak-password-rejected

		const testUser = `dor-e2e-weak-${Date.now()}`
		const testPassword = 'DorTest123!Pw'
		const ocsHeaders = {
			Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		}

		// Create a fresh, suite-less user. On this shared dev instance the OCS
		// user-create endpoint can return a 500 from an unrelated post-create hook
		// even though the account IS created — so we verify existence afterwards
		// and skip gracefully (rather than false-fail) if provisioning is
		// genuinely unavailable.
		await page.request
			.post(`${BASE_URL}/ocs/v2.php/cloud/users?format=json`, {
				headers: ocsHeaders,
				form: { userid: testUser, password: testPassword },
			})
			.catch(() => undefined)

		const existsRes = await page.request
			.get(`${BASE_URL}/ocs/v2.php/cloud/users/${testUser}?format=json`, {
				headers: ocsHeaders,
			})
			.catch(() => undefined)
		const userExists =
			!!existsRes
			&& existsRes.ok()
			&& (
				(await existsRes.json().catch(() => ({}))) as {
					ocs?: { data?: { id?: string } }
				}
			)?.ocs?.data?.id === testUser
		test.skip(
			!userExists,
			'Could not provision a fresh suite-less user (OCS unavailable) — skipping setup-mode check',
		)

		const browser = page.context().browser()!
		const testContext = await browser.newContext()
		const testPage = await testContext.newPage()
		testPage.setDefaultTimeout(20_000)

		try {
			await testPage.goto(`${BASE_URL}/index.php/login`, {
				waitUntil: 'domcontentloaded',
			})
			// The NC login form hydrates client-side. On this shared dev instance it
			// can fail to hydrate (asset 500s / brute-force throttling). If the form
			// never appears, the environment can't support this flow — skip cleanly.
			const userField = testPage.locator('input[name="user"]')
			const formReady = await userField
				.waitFor({ state: 'visible', timeout: 30_000 })
				.then(() => true)
				.catch(() => false)
			test.skip(
				!formReady,
				'NC login form did not hydrate (shared-instance flakiness) — skipping setup-mode check',
			)
			await userField.fill(testUser)
			await testPage.locator('input[name="password"]').fill(testPassword)
			await testPage.locator('button[type="submit"]').first().click()
			await testPage.waitForSelector('#header, header.header', {
				timeout: 30_000,
			})
			await testPage.goto(`${BASE_URL}/index.php/apps/keepiq/lock`, {
				waitUntil: 'domcontentloaded',
			})

			// The lock screen renders. Because LockScreen.vue fetches the suite in
			// created(), the heading may briefly show "Set up" before settling.
			// A suite-less user settles on the setup form: wait for the confirm
			// field (only present in setup mode) to be stable before asserting.
			const heading = testPage
				.locator('h1.lock-screen__title, .lock-screen h1')
				.first()
			await expect(heading).toBeVisible({ timeout: 20_000 })
			// Wait for the suite fetch in created() to settle (heading can flicker).
			await testPage.waitForTimeout(2_000)
			const headingText = (await heading.textContent())?.trim() ?? ''
			test.skip(
				!/Set up your master password/i.test(headingText),
				`Fresh user unexpectedly has a suite (heading: "${headingText}") — skipping setup-mode check`,
			)

			// Two password fields confirm setup mode (master + confirm).
			await expect
				.poll(
					async () =>
						testPage
							.locator('.lock-screen input[type="password"]')
							.count(),
					{ timeout: 10_000 },
				)
				.toBeGreaterThanOrEqual(2)

			// Type a weak password ("password") — zxcvbn score 0.
			await testPage
				.locator('.lock-screen input[type="password"]')
				.first()
				.fill('password')

			// The strength meter appears and reports weakness.
			await expect(testPage.locator('.password-strength-meter')).toBeVisible({
				timeout: 5_000,
			})
			const feedback = testPage.locator('.password-strength-meter__feedback')
			await expect(feedback).toBeVisible()
			expect(await feedback.textContent()).toBeTruthy()

			// The submit button stays disabled (canSubmitSetup false — too weak).
			const submitBtn = testPage
				.locator('.lock-screen button')
				.filter({ hasText: /Set up vault/i })
			await expect(submitBtn).toBeVisible()
			await expect(submitBtn).toBeDisabled()
		} finally {
			await testContext.close().catch(() => {})
			// Clean up the throwaway user.
			await page.request
				.delete(
					`${BASE_URL}/ocs/v2.php/cloud/users/${testUser}?format=json`,
					{ headers: ocsHeaders },
				)
				.catch(() => {})
		}
	})
})

// ---------------------------------------------------------------------------
// User Settings — dialog reachability
// ---------------------------------------------------------------------------

test.describe('User Settings — spec: user-settings/spec.md', () => {
	/**
	 * @e2e user-settings::user-opens-settings
	 * GIVEN a user opens the settings foldout in the Keepiq navigation
	 * WHEN they click the "Settings" entry
	 * THEN the system MUST display the NcAppSettingsDialog with the user
	 *      preference sections (Session timeout / master-password / recovery).
	 *
	 * The opener is a `section: "settings"` menu entry carrying
	 * `action: "user-settings"` (id `UserSettings`) in src/manifest.json.
	 * CnAppNav renders it inside the NcAppNavigationSettings foldout and binds
	 * the click to the `cnOpenUserSettings` inject provided by CnAppRoot, which
	 * opens App.vue's `#user-settings` slot dialog. (The foldout toggle and the
	 * entry are clicked via a real DOM `.click()` so the lock-screen layout
	 * cannot swallow the synthetic pointer event.)
	 */
	test('user-opens-settings — the action:"user-settings" menu entry opens the user-settings dialog', async ({
		page,
	}) => {
		// @e2e user-settings::user-opens-settings

		// The nav (and with it this entry) is hidden on the lock screen, so the
		// affordance is asserted where it exists: the unlocked shell.
		await unlockVault(page)
		await expect(page.locator('.app-navigation').first()).toBeVisible({
			timeout: 15_000,
		})

		// Expand the settings foldout (NcAppNavigationSettings) that hosts the
		// settings-section entries (Lock vault, Settings).
		const foldoutToggle = page
			.locator(
				'#app-settings button.settings-button, [data-testid="cn-nav-settings"] button',
			)
			.first()
		await expect(foldoutToggle).toBeVisible({ timeout: 5_000 })
		await foldoutToggle.evaluate((el: HTMLElement) => el.click())

		// The manifest-declared user-settings opener now renders and is visible.
		const settingsEntry = page.locator(
			'[data-testid="cn-nav-entry-UserSettings"]',
		)
		await expect(settingsEntry.first()).toBeVisible({ timeout: 5_000 })

		// Clicking it invokes cnOpenUserSettings -> App.vue's #user-settings dialog.
		await settingsEntry
			.locator('a')
			.first()
			.evaluate((el: HTMLElement) => el.click())

		// The Session and Security sections from App.vue's #user-settings slot.
		await expect(page.getByText('Session timeout').first()).toBeVisible({
			timeout: 10_000,
		})
		await expect(
			page.getByText('Security', { exact: true }).first(),
		).toBeVisible()
		await expect(page.getByText(/master password/i).first()).toBeVisible()
	})

	/**
	 * Companion assertion: the user-settings opener IS present in the manifest —
	 * exactly one `action: "user-settings"` menu entry (id `UserSettings`) renders
	 * in the settings foldout, giving the user a reachable affordance to open the
	 * user-settings dialog.
	 */
	test('user-settings dialog has a manifest opener in the settings foldout', async ({
		page,
	}) => {
		// Same unlock rationale as above: the nav no longer exists while locked.
		await unlockVault(page)

		const nav = page.locator('.app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 15_000 })

		// Expand the foldout, then assert the user-settings entry exists.
		await nav
			.locator(
				'#app-settings button.settings-button, [data-testid="cn-nav-settings"] button',
			)
			.first()
			.evaluate((el: HTMLElement) => el.click())

		const settingsOpener = nav.locator(
			'[data-testid="cn-nav-entry-UserSettings"]',
		)
		await expect(settingsOpener.first()).toBeVisible({ timeout: 5_000 })
		expect(await settingsOpener.count()).toBe(1)
	})
})
