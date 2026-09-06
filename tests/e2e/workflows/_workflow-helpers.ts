import type { Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared helpers for the Keepiq DEEP, data-dependent workflow e2e layer.
 *
 * Unlike the spec-coverage suite (which only asserts the lock screen and the
 * router-guard redirect), these workflow specs DRIVE the vault: they unlock
 * with the development master password, and exercise the secret read / delete
 * surfaces and the encryption round-trip through the app's real REST API and
 * WebCrypto primitives.
 *
 * Environment assumptions (verified live, 2026-06-10):
 *   - The keepiq app is enabled and serving at /index.php/apps/keepiq/.
 *   - The admin user owns ONE active EncryptionSuite, seeded by the
 *     `SeedDevelopmentData` install repair step with the known development
 *     master password `Oj` (a 2-char password — valid for UNLOCK, which has no
 *     strength gate; it would NOT pass the 12-char setup strength floor).
 *   - The suite's private key (PKCS#8) is AES-GCM-wrapped with that password
 *     and decrypts in-browser; the suite's `certificate` field is a full
 *     CA-signed X.509 certificate.
 */
import { expect } from '@playwright/test'

export const APP_BASE = '/index.php/apps/keepiq'

/**
 * The known development master password. Seeded by
 * lib/Repair/SeedDevelopmentData.php (DEV_MASTER_PASSWORD = 'Oj').
 *
 * ⚠️ THE TARGET INSTANCE MUST HAVE `debug` ENABLED:
 *
 *     occ config:system:set debug --value=true --type=boolean
 *     occ app:disable keepiq && occ app:enable keepiq   # re-run repair steps
 *
 * `SeedDevelopmentData::run()` returns immediately when `debug` is false, so on
 * an instance without it NO dev vault is ever created. The app then sits in
 * first-time-setup mode ("Set up vault", disabled) and `unlockVault()` below
 * finds no "Unlock" button — every workflow spec fails on
 * `expect('.lock-screen').toHaveCount(0)`. That reads exactly like a broken
 * unlock flow and is really an unprovisioned instance: 35 of 56 specs failed
 * this way on a fresh isolated instance before the flag was set.
 */
export const DEV_MASTER_PASSWORD = 'Oj'

/** The lock-screen heading locator (covers both setup + unlock copy). */
export function lockHeading(page: Page) {
	return page.locator('h1.lock-screen__title, .lock-screen h1').first()
}

/**
 * Open the Keepiq lock screen and wait for the suite-state fetch to settle.
 *
 * LockScreen.created() runs fetchSuite() asynchronously; until it resolves,
 * `currentSuite` is null and the heading renders the SETUP copy, then flips to
 * the UNLOCK copy once the active suite arrives. Waiting only for "either"
 * heading races that flip, so we wait for the /api/v1/suites response and then
 * for the heading to STABILISE (two consecutive identical reads) before
 * returning. When admin owns an active suite this lands on "Unlock Keepiq".
 */
export async function gotoLockSettled(page: Page): Promise<string> {
	const suitesResp = page
		.waitForResponse((r) => /\/api\/v1\/suites(\?|$)/.test(r.url()), {
			timeout: 20_000,
		})
		.catch(() => null)
	// ADR-074 rule 4: `networkidle` cannot settle on Nextcloud. This helper
	// already carries a stronger readiness signal than a quiet network — the
	// /api/v1/suites response awaited below, plus the heading-stabilisation
	// loop — so the wait is dropped rather than replaced by a weaker one.
	await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
	await expect(lockHeading(page)).toBeVisible({ timeout: 20_000 })
	await suitesResp
	// Stabilise: poll the heading until two consecutive reads agree, so we never
	// observe the transient setup→unlock flip mid-fetch.
	let prev = ''
	for (let i = 0; i < 20; i++) {
		const cur = (await lockHeading(page).textContent())?.trim() ?? ''
		if (cur && cur === prev) break
		prev = cur
		await page.waitForTimeout(250)
	}
	return (await lockHeading(page).textContent())?.trim() ?? ''
}

/**
 * Fill the unlock password and click Unlock. The NcPasswordField wraps the
 * input; `fill({ force: true })` reliably triggers the v-model update so the
 * Unlock button enables.
 *
 * Returns the lock-screen error text (empty string when none) after a settle.
 */
export async function attemptUnlock(page: Page, password: string): Promise<string> {
	const input = page.locator('.lock-screen input[type="password"]').first()
	await input.fill(password, { force: true })
	const btn = page
		.locator('.lock-screen button')
		.filter({ hasText: /^\s*Unlock\s*$/i })
		.first()
	await expect(btn).toBeEnabled()
	await btn.click({ force: true })
	// Allow the WebCrypto decrypt + (attempted) router push to run.
	await page.waitForTimeout(4_000)
	const errCount = await page
		.locator('.lock-screen')
		.getByText(/Wrong master password|decryption failed/i)
		.count()
		.catch(() => 0)
	if (errCount > 0) {
		return (
			(
				await page
					.locator('.lock-screen')
					.getByText(/Wrong master password|decryption failed/i)
					.first()
					.textContent()
			)?.trim() ?? 'error'
		)
	}
	return ''
}

/**
 * Drive the lock screen all the way into an UNLOCKED vault session and wait for
 * a vault route to render. Returns when the lock screen is gone.
 *
 * Two harness facts make this reliable:
 *   - The themed `NcButton` swallows Playwright's synthetic `.click()`, so we
 *     dispatch a native `HTMLButtonElement.click()` via `page.evaluate` to fire
 *     the Vue `@click="handleUnlock"` handler (the same trick documented for the
 *     themed-submit swallow elsewhere in the fleet).
 *   - `session.unlock()` decrypts the seeded AES-GCM private-key envelope with
 *     the dev master password in-browser (PBKDF2 600k → AES-256-GCM), imports
 *     the RSA-OAEP key, and `handleUnlock` then pushes to the return URL.
 *
 * @param page The Playwright page.
 * @param password The master password (defaults to the dev password).
 * @return Resolves once the vault is unlocked and off the lock screen.
 */
export async function unlockVault(
	page: Page,
	password: string = DEV_MASTER_PASSWORD,
): Promise<void> {
	await gotoLockSettled(page)
	await page
		.locator('.lock-screen input[type="password"]')
		.first()
		.fill(password, { force: true })
	// Give the v-model a tick so the Unlock button enables.
	await page.waitForTimeout(300)
	// Native click — the themed NcButton swallows Playwright's synthetic click.
	await page.evaluate(() => {
		const btns = Array.from(
			document.querySelectorAll('.lock-screen button'),
		) as HTMLButtonElement[]
		const unlock = btns.find((b) => /Unlock/i.test(b.textContent || ''))
		if (unlock) {
			unlock.click()
		}
	})
	// Wait for the unlock + router push to settle off the lock screen.
	await expect(page.locator('.lock-screen')).toHaveCount(0, { timeout: 20_000 })
}

/**
 * Open the vault list WITHIN the unlocked SPA. A full `page.goto` reload would
 * wipe the in-memory CryptoKey and bounce to the lock screen, so this navigates
 * the router in place and the session stays unlocked.
 *
 * @param page The Playwright page (must already be unlocked).
 */
export async function openVault(page: Page): Promise<void> {
	await gotoVaultRoute(page, 'secrets')
	await expect(page.locator('.secret-list-view')).toBeVisible({ timeout: 20_000 })
}

/**
 * Open the vault page's single "Actions" overflow menu — the actions bar's
 * own NcActions (`data-testid="cn-actions"`), which since the Stage-8
 * toolbar consolidation carries EVERY page action (Refresh, Select all,
 * the create/import actions, the "My data" entries and the type filter).
 * The themed trigger button swallows Playwright's synthetic click, so the
 * click is fired natively (same as the unlock button above).
 *
 * @param page The Playwright page (must be on the vault list, unlocked).
 */
export async function openActionsMenu(page: Page): Promise<void> {
	await page
		.getByTestId('cn-actions')
		.locator('button')
		.first()
		.evaluate((el: HTMLElement) => el.click())
}

/**
 * Click an entry inside the vault page's "Actions" overflow menu.
 *
 * The overflow entries are NcActionButtons (restyle Stage 5), and NcActionButton
 * renders its `data-testid` on the `<li role="presentation">` WRAPPER while the
 * Vue click handler sits on the inner `button.action-button`. A native click on
 * the element that carries the testid therefore hits the presentational list
 * item and fires nothing — the dialog never opens and the spec times out on its
 * next locator. So: open the menu, then natively click the INNER button of the
 * testid-carrying wrapper (native, because the themed button swallows
 * Playwright's synthetic click, same as the unlock button above).
 *
 * @param page   The Playwright page (must be on the vault list, unlocked).
 * @param testid The `data-testid` of the overflow entry, e.g. 'import-secrets'.
 */
export async function clickOverflowAction(
	page: Page,
	testid: string,
): Promise<void> {
	await openActionsMenu(page)
	const inner = page.getByTestId(testid).locator('button').first()
	await inner.waitFor({ state: 'attached', timeout: 10_000 })
	await inner.evaluate((el: HTMLElement) => el.click())
}

/**
 * Navigate to an in-app route WITHIN the already-unlocked SPA, in place.
 *
 * ⚠️ This MUST NOT reload the page. The vault's CryptoKey lives only in memory,
 * so a `page.goto` to any in-app route drops it and the router guard bounces
 * straight back to the lock gate.
 *
 * The router moved from hash mode to `createWebHistory` (clean path URLs), and
 * that is why this helper is written against the router instance rather than
 * the URL. Under hash mode, `location.hash = '#/secrets'` both changed the URL
 * and drove the route. Under path mode the same line still "works" — it appends
 * a fragment and fires `hashchange` — but `createWebHistory` does not listen to
 * `hashchange`, so the route never changes and NOTHING throws. Every caller
 * then failed much later, on a missing `.secret-list-item`, which reads as a
 * broken vault rather than a navigation that silently did nothing.
 *
 * `$router.push` is an in-place SPA navigation, so the key survives. The
 * pushState fallback exists only for the case where the app handle is not
 * exposed; it drives `createWebHistory`'s own `popstate` listener.
 *
 * @param page  The Playwright page (must already be unlocked).
 * @param route The in-app route WITHOUT a leading slash, e.g. 'secrets',
 *              'password-health', or '' for the dashboard root.
 */
export async function gotoVaultRoute(page: Page, route: string): Promise<void> {
	const path = `/${route}`.replace(/\/+$/, '') || '/'
	await page.evaluate((p) => {
		const host = document.querySelector('#keepiq-app') as
			| (HTMLElement & {
					__vue_app__?: {
						config?: {
							globalProperties?: {
								$router?: { push: (to: string) => unknown }
							}
						}
					}
			  })
			| null
		const router = host?.__vue_app__?.config?.globalProperties?.$router
		if (router) {
			router.push(p)
			return
		}
		// No app handle: drive createWebHistory's popstate listener directly.
		// The base is derived exactly as `routerBase()` in src/main.js does, so
		// both the `/apps/` and `/index.php/apps/` URL forms resolve.
		const base =
			window.location.pathname.match(/^(.*\/apps\/keepiq)(?:\/|$)/)?.[1] ?? ''
		window.history.pushState({}, '', `${base}${p}`)
		window.dispatchEvent(new PopStateEvent('popstate', { state: {} }))
	}, path)
	// Polling surfaces never reach networkidle, so wait on the DOM instead.
	await page.waitForLoadState('domcontentloaded')
	await page.waitForTimeout(500)
}

/**
 * The Nextcloud request token, read from the document. Required for
 * state-changing API calls made from within page.evaluate.
 */
export const READ_REQUEST_TOKEN = `(() => {
	const head = document.querySelector('head[data-requesttoken]');
	return (head && head.getAttribute('data-requesttoken'))
		|| (window.OC && window.OC.requestToken) || '';
})()`
