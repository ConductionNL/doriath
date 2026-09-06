/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage — Keepiq app navigation (manifest-driven CnAppRoot
 * shell).
 *
 * Asserts the left-hand app navigation renders the manifest menu entries and
 * that clicking a gated nav entry routes through the lock guard. Nav clicks are
 * scoped to the app navigation (never the global NC header) per the nav trap.
 *
 * @e2e openspec/specs/menu-architecture/spec.md#app-navigation-renders
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
// The lock screen is now an EXCLUSIVE surface: App.vue hides `.app-navigation`
// on the Lock route, so nav coverage requires an unlocked vault. This borrows
// the workflow layer's unlock (dev master password, debug instances only) —
// the one deliberate exception to this suite's no-unlock rule, because the
// asserted surface no longer exists while locked.
import { unlockVault } from '../workflows/_workflow-helpers.ts'
import {
	APP_BASE,
	assertNoKeepiqErrors,
	collectKeepiqErrors,
	lockHeading,
} from './_helpers.ts'

// The app's left navigation is the `.app-navigation` container. We scope ALL
// nav queries to it (never the global NC header / apps menu) and additionally
// match keepiq-owned hrefs, so we can never click a global app link.
function appNav(page: Page) {
	return page.locator('.app-navigation').first()
}

// Settings-section menu entries (Lock vault, Settings) render inside the
// NcAppNavigationSettings foldout, which starts collapsed. Expand it so those
// entries become visible. The toggle is clicked via a real DOM `.click()` so
// the full-page lock-screen layout cannot swallow the synthetic pointer event.
async function expandSettingsFoldout(page: Page) {
	const toggle = page
		.locator(
			'#app-settings button.settings-button, [data-testid="cn-nav-settings"] button',
		)
		.first()
	await expect(toggle).toBeVisible({ timeout: 5_000 })
	await toggle.evaluate((el: HTMLElement) => el.click())
}

test.describe('App navigation — manifest menu', () => {
	test('the app navigation is hidden on the lock screen', async ({ page }) => {
		// The unlock/setup prompt is the ONLY interactive surface while locked:
		// App.vue's `keepiq-shell--locked` modifier hides `.app-navigation`
		// (and its floating toggle) on the Lock route. This is the inverse of
		// what this spec used to assert — the old behaviour (a fully clickable
		// sidebar beside the lock prompt) was the bug.
		const errors = collectKeepiqErrors(page)
		await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })

		await expect(appNav(page)).toBeHidden()

		assertNoKeepiqErrors(errors)
	})

	test('left navigation renders the manifest menu entries once unlocked', async ({
		page,
	}) => {
		const errors = collectKeepiqErrors(page)
		await unlockVault(page)

		const nav = appNav(page)
		await expect(nav).toBeVisible({ timeout: 15_000 })

		// The Dashboard entry points at the keepiq app root (not /apps/dashboard).
		// Matched on the SUFFIX, not the whole href: the app routes on history
		// now, and Nextcloud serves it as both `/apps/keepiq/...` and
		// `/index.php/apps/keepiq/...`, so the link carries whichever base the
		// page was loaded under. Asserting a literal absolute form would pin one
		// of the two and fail on the other for a reason that is not a defect.
		await expect(nav.locator('a[href$="/apps/keepiq/"]').first()).toBeVisible()
		// Footer entry: Documentation (pinned, always visible).
		await expect(nav.getByText(/Documentation/i).first()).toBeVisible()
		// Lock vault lives in the settings foldout (section: "settings"); expand
		// it so the keepiq-owned lock route becomes visible.
		await expandSettingsFoldout(page)
		await expect(nav.locator('a[href$="/lock"]').first()).toBeVisible()

		assertNoKeepiqErrors(errors)
	})

	test('clicking the Lock vault nav entry locks the vault and hides the nav', async ({
		page,
	}) => {
		await unlockVault(page)

		// "Lock vault" is a keepiq-owned route (/apps/keepiq/lock) in the
		// settings foldout — expand it and click the entry. App.vue's $route
		// watcher calls session.lock() on entering /lock while unlocked, so
		// this drives the real re-lock flow end to end.
		await expandSettingsFoldout(page)
		const lockEntry = appNav(page).locator('a[href$="/lock"]').first()
		await expect(lockEntry).toBeVisible()
		await lockEntry.evaluate((el: HTMLElement) => el.click())

		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
		// History router: the lock route is a real path. Anchored at the end so
		// it holds under both the `/apps/...` and `/index.php/apps/...` bases.
		await expect(page).toHaveURL(/\/apps\/keepiq\/lock$/)
		// Back on the lock gate the nav disappears again — the exclusive-surface
		// contract, asserted on the transition and not only on a cold load.
		await expect(appNav(page)).toBeHidden()
	})
})
