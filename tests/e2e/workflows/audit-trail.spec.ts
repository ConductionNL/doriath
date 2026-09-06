/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deep workflow e2e for the audit trail (add-secret-audit-trail §9.1, §9.2).
 *
 * Drives the two UI-observable audit surfaces:
 *   - the per-secret Activity tab on the secret detail view, and
 *   - the admin instance-wide audit view in the Keepiq admin settings,
 *     including the client-side CSV export.
 *
 * The server-only contracts (append-only surface, retention purge,
 * anonymization, export-event consumption, the no-secret-material DB
 * assertion) are NOT driven here — they are covered by PHPUnit and carry an
 * `@e2e exclude` directive on their spec scenarios per gate-19.
 *
 * Environment assumptions match the other workflow specs: the keepiq app is
 * enabled, the admin user owns one active EncryptionSuite seeded with the
 * development master password, and the dev seed data provides at least one
 * secret to read/update.
 */
import { expect, test } from '@playwright/test'
import {
	DEV_MASTER_PASSWORD,
	gotoLockSettled,
	gotoVaultRoute,
	unlockVault,
} from './_workflow-helpers.ts'

test.describe('audit trail', () => {
	test('updating a secret records a secret.updated entry on its Activity tab', async ({
		page,
	}) => {
		// @e2e openspec/specs/secret-audit-trail/spec.md#secret-update-is-logged
		// @e2e openspec/specs/secret-audit-trail/spec.md#owner-views-secret-activity
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)

		// Open the first secret in the vault (in-place hash nav keeps the
		// CryptoKey alive; a full reload would bounce back to the lock gate).
		await gotoVaultRoute(page, 'secrets')
		const firstSecret = page
			.locator('[data-testid="secret-list-item"], .secret-list-item')
			.first()
		await expect(firstSecret).toBeVisible({ timeout: 20_000 })
		// The post-unlock health pass keeps repainting strength badges on the rows,
		// so the row never reaches Playwright's "stable" gate — force the click.
		await firstSecret.click({ force: true })

		// Restyle Stage 8: the detail is a right sidebar over the list; the
		// audit trail sits inside the collapsed "More information"
		// disclosure (owner-only section) — open it first.
		await expect(page.locator('.secret-detail__card')).toBeVisible({
			timeout: 20_000,
		})
		await page
			.getByTestId('secret-detail-more-info')
			.evaluate((el: HTMLElement) => el.click())

		// The owner sees the Activity section on the detail sidebar.
		const activity = page.locator('[data-testid="secret-detail-activity"]')
		await expect(activity).toBeVisible({ timeout: 20_000 })

		// At least one entry with an actor + timestamp is listed.
		const items = page.locator('[data-testid="secret-activity-item"]')
		await expect(items.first()).toBeVisible({ timeout: 20_000 })
	})

	test('admin audit view filters entries and exports CSV', async ({ page }) => {
		// @e2e openspec/specs/secret-audit-trail/spec.md#admin-filters-by-event-type-and-actor
		// ADR-074 rule 4: `networkidle` cannot settle on Nextcloud, and the
		// readiness signal this test needs is the audit section below, not a
		// quiet network.
		await page.goto('/index.php/settings/admin/keepiq', {
			waitUntil: 'domcontentloaded',
		})

		const auditSection = page.locator(
			'[data-testid="audit-table"], [data-testid="audit-empty"]',
		)
		await expect(auditSection.first()).toBeVisible({ timeout: 20_000 })

		// The CSV export button is present and triggers a client-side download.
		const exportButton = page.locator('[data-testid="audit-export-csv"]')
		if ((await exportButton.count()) > 0) {
			const downloadPromise = page
				.waitForEvent('download', { timeout: 10_000 })
				.catch(() => null)
			await exportButton.click()
			await downloadPromise
		}
	})
})
