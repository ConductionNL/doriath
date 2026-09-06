/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deep workflow e2e for password health (password-health).
 *
 * Drives the DOM-observable health surfaces against an unlocked vault:
 *   - the strength badge in the secrets list,
 *   - the vault health report (score + category findings with deep links),
 *   - the locked-vault state (no badges; dashboard "Unlock to analyse"),
 *   - the breach-check gating (no opt-in / proxy traffic when the admin gate
 *     is off).
 *
 * The non-DOM contracts are NOT driven here and carry an exclusion directive
 * on their spec scenarios per gate-19: the in-memory plaintext/worker
 * discard, the SHA-256 reuse-map internals, the prefix-only HIBP wire shape, the
 * key_updated_at server-maintenance, the no-health-write-surface route
 * enumeration, and the proxy logging — all covered by vitest + PHPUnit.
 *
 * Environment assumptions match the other workflow specs: the keepiq app is
 * enabled, the admin user owns one active EncryptionSuite seeded with the
 * development master password, and the dev seed data provides at least one
 * secret to analyse.
 */
import { expect, test } from '@playwright/test'
import {
	APP_BASE,
	DEV_MASTER_PASSWORD,
	gotoLockSettled,
	gotoVaultRoute,
	unlockVault,
} from './_workflow-helpers.ts'

test.describe('password health', () => {
	test('the secrets list shows a strength badge after unlock', async ({
		page,
	}) => {
		// @e2e password-health::weak-password-badged
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)

		await gotoVaultRoute(page, 'secrets')

		// At least one secret renders; once the post-unlock health pass runs a
		// strength badge appears on a password-bearing secret.
		const firstSecret = page
			.locator('[data-testid="secret-list-item"], .secret-list-item')
			.first()
		await expect(firstSecret).toBeVisible({ timeout: 20_000 })
		const badge = page.locator('[data-testid^="strength-badge-"]').first()
		await expect(badge).toBeVisible({ timeout: 20_000 })
	})

	test('the health report lists categories and deep-links to a secret', async ({
		page,
	}) => {
		// @e2e password-health::report-lists-findings-with-deep-links
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)

		await gotoVaultRoute(page, 'password-health')

		const report = page.locator('[data-testid="health-report"]')
		await expect(report).toBeVisible({ timeout: 20_000 })

		// The score renders once analysis settles.
		await expect(page.locator('[data-testid="health-score"]')).toBeVisible({
			timeout: 20_000,
		})

		// The weak-passwords category section is present.
		await expect(page.locator('[data-testid="category-weak"]')).toBeVisible()

		// A finding (if any) deep-links to the secret detail.
		const finding = page.locator('[data-testid^="finding-"]').first()
		if ((await finding.count()) > 0) {
			await finding.click()
			await expect(page).toHaveURL(/\/secrets\//, { timeout: 20_000 })
		}
	})

	test('a locked vault exposes no health data (router gates the dashboard)', async ({
		page,
	}) => {
		// @e2e password-health::locked-vault-shows-no-health-data
		// Do NOT unlock. A fresh load of the dashboard route while locked is
		// redirected to the lock gate by the zero-knowledge router guard (the same
		// behaviour the gated-routes spec verifies for every in-app route), so the
		// dashboard — and any health data — never mounts.
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.lock-screen')).toBeVisible({ timeout: 20_000 })

		// No health data leaks while locked: no strength badges, no dashboard card.
		await expect(page.locator('[data-testid^="strength-badge-"]')).toHaveCount(0)
		await expect(
			page.locator('[data-testid="health-card-unlocked"]'),
		).toHaveCount(0)
	})

	test('breach opt-in is absent when the admin gate is off', async ({ page }) => {
		// @e2e password-health::admin-gate-off-means-no-traffic
		const breachRequests: string[] = []
		page.on('request', (req) => {
			if (/breach-check\/range/.test(req.url())) {
				breachRequests.push(req.url())
			}
		})

		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await gotoVaultRoute(page, 'password-health')
		await expect(page.locator('[data-testid="health-report"]')).toBeVisible({
			timeout: 20_000,
		})

		// With the default-off admin gate, the opt-in control is not rendered and
		// no breach proxy request is made.
		await expect(page.locator('[data-testid="breach-optin"]')).toHaveCount(0)
		expect(breachRequests).toHaveLength(0)
	})
})
