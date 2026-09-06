import type { Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared helpers for Keepiq spec-coverage Playwright tests.
 *
 * Keepiq is a zero-knowledge encrypted vault: every in-app view (Dashboard,
 * Vault, Features & roadmap, Secret detail) sits behind a master-password lock
 * screen and a router guard. The admin account already owns an EncryptionSuite,
 * but its master password is — by design — not recoverable (never stored server
 * side). So the lock screen and the router-guard redirect ARE the canonical,
 * data-independent UI surfaces for the gated routes, and the admin settings page
 * (a Nextcloud settings section, ungated) is fully reachable.
 *
 * These helpers therefore assert real DOM behaviour without ever needing to
 * unlock the vault.
 */
import { expect } from '@playwright/test'

export const APP_BASE = '/index.php/apps/keepiq'

/**
 * Attach a keepiq-origin error/5xx collector to a page. Returns the array that
 * accumulates offending messages. We deliberately ignore errors from other apps
 * (photos, core user-status, etc.) which are noisy on this shared dev instance.
 */
export function collectKeepiqErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() !== 'error') return
		const text = msg.text()
		// Ignore expected/benign noise:
		//  - NC theming: a token CSS file occasionally 404s mid-run.
		//  - A parametrised route (e.g. #/secrets/placeholder-id) briefly mounts
		//    its detail component before the lock guard redirects, firing one
		//    expected 404 "Failed to load resource" for the non-existent id.
		if (/Refused to apply style|MIME type/i.test(text)) return
		if (/Failed to load resource.*404|status of 404/i.test(text)) return
		// Only flag errors that name keepiq or come from a keepiq bundle.
		const loc = msg.location()?.url ?? ''
		if (/keepiq/i.test(text) || /keepiq/i.test(loc)) {
			errors.push(`console.error: ${text}`)
		}
	})
	page.on('response', (res) => {
		if (res.status() >= 500 && /\/keepiq\//.test(res.url())) {
			errors.push(`HTTP ${res.status()}: ${res.url()}`)
		}
	})
	return errors
}

/** Open the Keepiq lock screen directly. */
export async function gotoLock(page: Page): Promise<void> {
	await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
}

/**
 * Open a hash-routed Keepiq view (e.g. '#/secrets'). Because the vault is
 * locked, the router guard redirects to the lock screen — which is exactly the
 * behaviour the per-route specs assert.
 */
export async function gotoRoute(page: Page, hash: string): Promise<void> {
	await page.goto(`${APP_BASE}/${hash}`, { waitUntil: 'domcontentloaded' })
}

/** The lock-screen heading locator (covers both setup + unlock copy). */
export function lockHeading(page: Page) {
	return page.locator('h1.lock-screen__title, .lock-screen h1').first()
}

/** Wait for the lock screen to be mounted and visible. */
export async function expectLockScreen(page: Page): Promise<void> {
	await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
	await expect(lockHeading(page)).toHaveText(
		/Unlock Keepiq|Set up your master password/i,
	)
}

/** Assert no keepiq-origin console errors / 5xx were collected. */
export function assertNoKeepiqErrors(errors: string[]): void {
	expect(errors, `keepiq-origin errors:\n${errors.join('\n')}`).toEqual([])
}
