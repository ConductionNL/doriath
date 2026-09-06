/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar to `tests/e2e/.auth/admin.json`.
 *
 * Every spec reuses the storage state via playwright.config.ts
 * `use.storageState`, so specs start from an already-authenticated
 * session without repeating the login flow.
 */

import type { FullConfig } from '@playwright/test'

import { chromium, request } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseUrl } from './base-url.ts'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, {
			failOnStatusCode: false,
		})
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. `
					+ 'Make sure the docker container is running.',
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

/**
 * Returns true when the cached storageState still authenticates against NC,
 * letting us skip a fresh (throttle-prone) login.
 */
async function cachedSessionValid(baseURL: string): Promise<boolean> {
	if (!fs.existsSync(STORAGE_STATE)) {
		return false
	}
	try {
		const state = JSON.parse(fs.readFileSync(STORAGE_STATE, 'utf8'))
		const ctx = await request.newContext({ baseURL, storageState: state })
		try {
			const res = await ctx.get('/ocs/v2.php/cloud/user?format=json', {
				headers: { 'OCS-APIRequest': 'true' },
				failOnStatusCode: false,
			})
			return res.status() === 200
		} finally {
			await ctx.dispose()
		}
	} catch {
		return false
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	// No localhost:8080 fallback — that is the SHARED dev container, and this
	// function performs LOGINS.
	const baseURL =
		(config.projects[0]?.use?.baseURL as string | undefined) ?? resolveBaseUrl()
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'

	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(AUTH_DIR, { recursive: true })

	// Reuse a still-valid cached session when possible. NC brute-force protection
	// throttles repeated logins on a busy shared instance, so avoid re-logging in
	// on every run: if the stored cookies still authenticate, skip the login flow.
	if (await cachedSessionValid(baseURL)) {
		return
	}

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// The NC login form hydrates client-side and this shared dev instance can be
	// sluggish (asset loads, brute-force throttling). Retry the whole login a few
	// times with generous waits so a single slow hydrate doesn't fail every spec.
	const MAX_ATTEMPTS = 4
	let loggedIn = false
	let lastErr: unknown
	for (let attempt = 1; attempt <= MAX_ATTEMPTS && !loggedIn; attempt++) {
		try {
			await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
			const userField = page.locator('input[name="user"]')
			await userField.waitFor({ state: 'visible', timeout: 40_000 })
			await userField.fill(username)
			await page.locator('input[name="password"]').fill(password)
			await page.locator('button[type="submit"]').first().click()
			await page.waitForSelector('#header, header.header', {
				state: 'attached',
				timeout: 40_000,
			})
			// Give the redirect a moment to settle, then confirm we left /login.
			await page.waitForLoadState('domcontentloaded').catch(() => {})
			if (!/\/login(\?|$|\/)/.test(page.url())) {
				loggedIn = true
			} else {
				lastErr = new Error(`still on ${page.url()} after submit`)
			}
		} catch (e) {
			lastErr = e
		}
		if (!loggedIn && attempt < MAX_ATTEMPTS) {
			await page.waitForTimeout(5_000 * attempt)
		}
	}

	if (!loggedIn) {
		throw new Error(
			`Login failed after ${MAX_ATTEMPTS} attempts (last: ${String(lastErr)}). `
				+ 'Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin) and that the '
				+ 'instance is reachable / not brute-force throttled.',
		)
	}

	/*
	 * Suppress the product walkthrough (ADR-043) for automated runs, the way
	 * dossiq's global-setup already does. The tour's "seen" state is per USER,
	 * not per test, so whichever spec runs first wears the tour and the rest
	 * inherit a dismissed one — an order-dependent suite. `audit-trail.spec.ts`
	 * started needing a retry the moment the walkthrough landed (#484), having
	 * never retried in the three preceding runs on development.
	 *
	 * This is NOT covered by #486: that withholds the tour while the vault is
	 * LOCKED, which is a different concern. Every spec here unlocks, and the
	 * tour is offered on the first unlocked visit by design.
	 *
	 * The sentinel is deliberately higher than any real app version, so every
	 * step's `sinceVersion` sorts below it and the tour composes to an empty
	 * step set rather than merely starting dismissed. localStorage is the right
	 * lever despite keepiq declaring a `completionConfigKey`:
	 * loadWalkthroughSeenVersion() reads a `{ value: null }` server answer —
	 * what a fresh user gets — as "never seen" and returns the local mirror, so
	 * the seeded value wins. The page is already on the instance origin after
	 * login, which is the origin storageState persists.
	 */
	try {
		await page.evaluate(() => {
			try {
				window.localStorage.setItem('cn-walkthrough-seen:keepiq', '999.0.0')
			} catch {
				// localStorage unavailable — specs fall back to dismissing by hand.
			}
		})
	} catch {
		// Never fail setup over an optional convenience.
	}

	/*
	 * Settle the support note the same way, and for a harder reason: it is not
	 * a convenience, it is a mask.
	 *
	 * `CnAppRoot` mounts `CnSupportDialog` on first open — `supportDialog`
	 * defaults to true — and the dialog is a real `aria-modal` overlay. It does
	 * not merely cover the page, it INTERCEPTS POINTER EVENTS: every click in
	 * the app lands on `cn-support-dialog__paragraph` instead of its target,
	 * and the failure names that paragraph rather than anything the spec is
	 * about. Five app-chrome specs have died in their own `beforeEach` on this
	 * since it shipped, all of them timing out on a button that was visible,
	 * enabled and stable the whole time.
	 *
	 * Settled ONCE here rather than per spec, because the marker is per USER:
	 * dismissing it inside a spec makes the suite order-dependent, with
	 * whichever spec runs first wearing the dialog and the rest inheriting a
	 * dismissed one.
	 *
	 * localStorage only, unlike some apps in the fleet. `useSupportDialog`
	 * reads the app's `/api/preferences/support-dialog-seen` first and falls
	 * back to `cn-support-dialog-shown:<appId>`; keepiq ships no such endpoint,
	 * so the local key IS the mechanism here, not a belt-and-braces second
	 * copy. The appId is the one App.vue passes to CnAppRoot.
	 */
	try {
		await page.evaluate(() => {
			try {
				window.localStorage.setItem('cn-support-dialog-shown:keepiq', '1')
			} catch {
				// A browser with site data blocked. Specs then hit the mask and
				// say so, which is better than passing for the wrong reason.
			}
		})
	} catch {
		// Never fail setup over an optional convenience.
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
