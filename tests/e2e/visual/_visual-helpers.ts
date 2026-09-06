import type { Locator, Page } from '@playwright/test'

/*
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared helpers for the visual-regression layer (GAP-5).
 *
 * This module is intentionally self-contained (imports only @playwright/test)
 * and is copied verbatim into each app's tests/e2e/visual/ directory so every
 * app's visual suite is identical and portable.
 *
 * Determinism is the whole point of a visual baseline: a flaky baseline is
 * worse than none. Every shot is taken with:
 *   - a fixed 1280x800 viewport (set on the `visual` project in the config),
 *   - CSS animations / transitions / caret blink disabled,
 *   - the auto-opening cn-support-dialog dismissed + hidden,
 *   - dynamic regions masked (dates, ids, avatars, counts, relative times),
 *   - a wait for *content* (no spinners / skeletons) before the shot,
 *   - maxDiffPixelRatio tolerance to absorb sub-pixel font hinting.
 *
 * PLATFORM CAVEAT (read before trusting a CI run): Playwright screenshot
 * baselines are rendered by the host's font stack + GPU, so a baseline shot on
 * a dev workstation will NOT byte-match the same page rendered on a CI Linux
 * runner. Baselines committed here are generated against the local dev
 * container. In CI the visual project must therefore either (a) regenerate its
 * own baselines on first run, or (b) stay non-gating until baselined in the CI
 * environment. See tests/e2e/visual/README in-repo wiring notes.
 */
import { expect } from '@playwright/test'

/** Common screenshot options applied to every visual assertion. */
export const SHOT_OPTIONS = {
	animations: 'disabled' as const,
	// Absorb sub-pixel font hinting / antialiasing differences without
	// hiding real layout regressions.
	maxDiffPixelRatio: 0.02,
	// Full page so a regression below the fold is still caught.
	fullPage: true,
}

/**
 * Inject a stylesheet that kills animations, transitions and the caret so a
 * shot taken mid-frame is identical every run.
 */
export async function freezePage(page: Page): Promise<void> {
	await page.addStyleTag({
		content: `
			*, *::before, *::after {
				animation-duration: 0s !important;
				animation-delay: 0s !important;
				transition-duration: 0s !important;
				transition-delay: 0s !important;
				caret-color: transparent !important;
				scroll-behavior: auto !important;
			}
			/* Hide the auto-opening support dialog + its backdrop entirely so
			   it can never bleed into a shot even if dismissal races. */
			[data-testid-modal="cn-support-dialog"],
			.cn-support-dialog,
			.modal-mask { visibility: hidden !important; }
		`,
	})
}

/**
 * Dismiss the "Support <App>" dialog that auto-opens over the app and would
 * otherwise dominate (and randomise) the shot.
 */
export async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('[data-testid-modal="cn-support-dialog"]')
	if (await dialog.isVisible().catch(() => false)) {
		await dialog
			.getByRole('button', { name: 'Close' })
			.click()
			.catch(() => {})
		await dialog.waitFor({ state: 'hidden', timeout: 5_000 }).catch(() => {})
	}
}

/**
 * Wait until the app content area has rendered and any loading spinner /
 * skeleton has gone. We use domcontentloaded + explicit waits because the NC
 * SPA keeps background XHR alive, so `networkidle` never fires.
 */
export async function waitForContentReady(page: Page): Promise<void> {
	await expect(
		page
			.locator('#header, header.header-appcontainer, .header-appcontainer')
			.first(),
	).toBeVisible({ timeout: 25_000 })
	await expect(
		page.locator('main, #app-content, .app-content, #content-vue').first(),
	).toBeVisible({ timeout: 20_000 })

	// Give Vue a beat to paint, then wait for spinners/skeletons to disappear.
	const spinner = page.locator(
		'.icon-loading, .loading, .material-design-icon.loading-icon, [class*="skeleton"], .app-content-loading',
	)
	await spinner
		.first()
		.waitFor({ state: 'hidden', timeout: 8_000 })
		.catch(() => {})

	// Wait for common async "Loading …" placeholder text to vanish. Many of
	// the Conduction dashboards stream widget data after first paint
	// ("Loading statistics…", "Loading version information…"); shooting before
	// it lands produces a non-deterministic baseline. Poll up to ~10s.
	const loadingText = page.getByText(
		/Loading[\s.]*(statistics|version|data|information)?…?/i,
	)
	for (let i = 0; i < 20; i++) {
		const count = await loadingText.count().catch(() => 0)
		if (count === 0) break
		const anyVisible = await loadingText
			.first()
			.isVisible()
			.catch(() => false)
		if (!anyVisible) break
		await page.waitForTimeout(500)
	}
	// Settle layout (fonts, async widgets) before the shot.
	await page.waitForTimeout(1_500)
}

/**
 * Dynamic regions that must be masked out of every shot so volatile content
 * (timestamps, uuids, user avatars, live counts, relative times) never flips a
 * baseline. Returns the locators present on the page; absent ones are simply
 * not masked.
 */
export function dynamicMasks(page: Page): Locator[] {
	const selectors = [
		// Nextcloud header right-side: user menu / avatar / notifications /
		// contacts menu / unified search — all volatile or focus-dependent.
		'#user-menu',
		'.avatardiv',
		'.user-menu',
		'#settings',
		'#notifications',
		'.notifications',
		'#contactsmenu',
		'.unified-search',
		'.header-right',
		// Common dynamic-content hooks across the Conduction apps.
		'[class*="timestamp"]',
		'[class*="date"]',
		'time',
		'[class*="relative-time"]',
		'[class*="last-modified"]',
		'[class*="updated"]',
		'[class*="uuid"]',
		'[class*="avatar"]',
		'[data-visual-mask]',
		// Count badges / stat numbers on dashboard cards + tables. These are
		// live data that churns between runs; mask the value, not the label.
		'.cn-stat-value',
		'[class*="stat-value"]',
		'.counter-bubble__counter',
		'[class*="statistic"]',
		'[class*="metric-value"]',
		'[class*="count"]',
		// Side detail / right sidebar panels stream live aggregates
		// ("Totals", "Loading statistics…") that never settle against a shared
		// live-data instance — mask the panel so structure stays the signal.
		'.app-content-details',
		'.app-sidebar',
		'[class*="dashboard-detail"]',
	]
	return selectors.map((s) => page.locator(s))
}

/**
 * Navigate, stabilise, and assert a full-page screenshot for one surface.
 */
export async function shootSurface(
	page: Page,
	url: string,
	name: string,
): Promise<void> {
	await page.goto(url, { waitUntil: 'domcontentloaded' })
	await dismissSupportDialog(page)
	await waitForContentReady(page)
	await freezePage(page)
	await expect(page).toHaveScreenshot(name, {
		...SHOT_OPTIONS,
		mask: dynamicMasks(page),
	})
}

/**
 * Assert that the component under test is ACTUALLY ON SCREEN, then shoot it.
 *
 * WHY THIS EXISTS — READ BEFORE ADDING A BASELINE
 * -----------------------------------------------
 * `shootSurface` above proves only that *a* page rendered. Nextcloud draws its
 * header, its left navigation and the app shell for every route, including the
 * ones that redirected somewhere else — so a screenshot named
 * `password-health.png` taken after a silent bounce to the lock screen is a
 * perfectly stable, perfectly green baseline of the wrong screen. Gate-26
 * cannot tell the difference: it only looks for the component's NAME somewhere
 * under `tests/e2e/visual/**`, and the name is in the filename either way.
 * Confirmed on this repo: a two-line file whose entire content was a COMMENT
 * naming one view took gate-26 from 10 findings to 9 — no screenshot, no
 * navigation, not even valid test code. The same trap in widget form is
 * recorded fleet-wide: asserting a `type:"data"` widget's TITLE tests the
 * manifest, not the render.
 *
 * ⚠️ Which is why the view is NOT named here. Writing the component's name into
 * this comment would itself satisfy gate-26 for that component — the corpus this
 * gate reads is the raw text of every file under `tests/e2e/visual/**`, comments
 * included, and this file is one of them. An earlier draft of this paragraph did
 * exactly that, and the negative control caught it: with the real specs moved
 * away the gate reported 7 uncovered components instead of 8, the missing one
 * being the view this sentence used to name. Document the defect without
 * spelling the token.
 *
 * So every baseline in this app goes through here, and every one of them names
 * a `data-testid` that ONLY the component under test renders. If we landed on
 * the lock screen, or on the dashboard, or on an error page, the `expect`
 * below fails and no screenshot is written — instead of a green baseline of
 * app chrome.
 *
 * @param page       The Playwright page, already navigated to the surface.
 * @param testId     A `data-testid` rendered ONLY by the component under test.
 * @param name       The baseline filename.
 */
export async function shootComponent(
	page: Page,
	testId: string,
	name: string,
): Promise<void> {
	await dismissSupportDialog(page)
	await waitForContentReady(page)
	// THE LOAD-BEARING ASSERTION. Not a soft check, not a `.catch(() => {})`:
	// if the component is not on screen there is nothing to baseline, and the
	// correct outcome is a red test.
	await expect(
		page.locator(`[data-testid="${testId}"]`),
		'the component under test did not render — a screenshot taken now would '
			+ `baseline Nextcloud chrome, not "${name}"`,
	).toBeVisible({ timeout: 20_000 })
	await freezePage(page)
	await expect(page).toHaveScreenshot(name, {
		...SHOT_OPTIONS,
		mask: dynamicMasks(page),
	})
}

/**
 * Land on the app root, then reach a surface by CLICKING its sidebar nav link
 * (by visible label) and shoot it. Many of the Conduction SPAs reset a
 * deep-link `goto('…#/route')` back to the dashboard, so an in-app nav click
 * is the reliable way to reach a non-default view.
 *
 * ⚠️ This helper used to end with "falls back to a shot of wherever we land if
 * the link is absent, so a baseline is still produced" — and did exactly that.
 * A baseline produced from wherever-we-landed is named after a component it
 * does not contain, and it is *stable*, so it never fails and never gets
 * looked at again. The nav link is now REQUIRED: if it is not there, the
 * surface was not reached and the test fails.
 */
export async function shootByNav(
	page: Page,
	appRoot: string,
	label: string,
	name: string,
): Promise<void> {
	await page.goto(appRoot, { waitUntil: 'domcontentloaded' })
	await dismissSupportDialog(page)
	await waitForContentReady(page)

	// Close any open detail/side panel that can overlay + swallow nav clicks.
	const panelClose = page
		.locator(
			'.app-content-details .icon-close, [class*="detail"] button[aria-label*="lose"], .app-sidebar__close',
		)
		.first()
	if (await panelClose.isVisible().catch(() => false)) {
		await panelClose.click().catch(() => {})
		await page.waitForTimeout(300)
	}

	const nav = page.locator('[id^="app-navigation"], .app-navigation, nav').first()
	const link = nav.getByRole('link', { name: label, exact: true }).first()
	await expect(
		link,
		`no navigation link labelled "${label}" — the surface for "${name}" was `
			+ 'never reached, so any screenshot taken here would be of another page',
	).toBeVisible({ timeout: 20_000 })
	await link.click()
	await dismissSupportDialog(page)
	await waitForContentReady(page)
	await freezePage(page)
	await expect(page).toHaveScreenshot(name, {
		...SHOT_OPTIONS,
		mask: dynamicMasks(page),
	})
}
