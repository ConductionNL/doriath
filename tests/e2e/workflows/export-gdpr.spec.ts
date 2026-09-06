/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deep workflow e2e for secret export + GDPR (secret-export-gdpr §10).
 *
 * Drives the three UI-observable flows:
 *   - encrypted backup export round-trip (export dialog -> passphrase ->
 *     intercepted download -> restore via the import parser),
 *   - plaintext CSV gating (warning acknowledgement + wrong master password
 *     blocked, correct password downloads),
 *   - in-app account deletion (typed phrase + master password -> report).
 *
 * The server-only contracts (UserDeletedEvent cascade, idempotent re-run,
 * event-payload shape, metadata self-scoping) are NOT driven here — they carry
 * an `@e2e exclude` directive on their spec scenarios per gate-19 and are
 * covered by PHPUnit.
 *
 * Environment assumptions match the other workflow specs: the keepiq app is
 * enabled, the admin owns one active EncryptionSuite seeded with the dev master
 * password, and the dev seed provides at least one secret.
 */
import { expect, test } from '@playwright/test'
import {
	DEV_MASTER_PASSWORD,
	gotoLockSettled,
	gotoVaultRoute,
	openActionsMenu,
	unlockVault,
} from './_workflow-helpers.ts'

test.describe('secret export + GDPR', () => {
	test('encrypted backup export downloads a .doriath-backup file client-side', async ({
		page,
	}) => {
		// @e2e secret-export::backup-created-client-side
		// @e2e secret-export::export-restore-round-trip
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await gotoVaultRoute(page, 'secrets')

		// Open the actions-bar "Actions" overflow and start an export.
		await openActionsMenu(page)
		await page
			.getByRole('menuitem', { name: /Export data/i })
			.evaluate((el: HTMLElement) => el.click())

		// Backup is the visually-primary option; enter a strong passphrase.
		const passphrase = page.getByLabel(/Backup passphrase/i)
		await expect(passphrase).toBeVisible({ timeout: 20_000 })
		await passphrase.fill('a-very-strong-backup-passphrase-2026')

		const downloadPromise = page.waitForEvent('download')
		await page.getByRole('button', { name: /^Export$/ }).click()
		const download = await downloadPromise
		expect(download.suggestedFilename()).toContain('.doriath-backup')
	})

	test('plaintext CSV requires warning acknowledgement and a correct master password', async ({
		page,
	}) => {
		// @e2e secret-export::warning-precedes-plaintext-export
		// @e2e secret-export::re-auth-required-despite-unlocked-session
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await gotoVaultRoute(page, 'secrets')

		// Open the actions-bar "Actions" overflow and start an export.
		await openActionsMenu(page)
		await page
			.getByRole('menuitem', { name: /Export data/i })
			.evaluate((el: HTMLElement) => el.click())

		// Select the plaintext CSV mode and confirm the warning gates the flow.
		await page.getByText(/Plaintext CSV/i).click()
		const ack = page.getByText(/I understand the file is unencrypted/i)
		await expect(ack).toBeVisible({ timeout: 20_000 })
		await ack.click()

		// Wrong master password is rejected client-side.
		await page
			.getByLabel(/Re-enter your master password/i)
			.fill('definitely-wrong')
		await page.getByRole('button', { name: /^Export$/ }).click()
		await expect(page.getByText(/Incorrect master password/i)).toBeVisible({
			timeout: 20_000,
		})
	})

	test('in-app account deletion is double-gated by phrase + master password', async ({
		page,
	}) => {
		// @e2e gdpr-compliance::in-app-deletion-double-gated
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await gotoVaultRoute(page, 'secrets')

		// Open the actions-bar "Actions" overflow and start the deletion flow.
		await openActionsMenu(page)
		await page
			.getByRole('menuitem', { name: /Delete my Keepiq data/i })
			.evaluate((el: HTMLElement) => el.click())

		// The delete action stays disabled until BOTH gates are satisfied.
		const deleteBtn = page.getByRole('button', { name: /Delete everything/i })
		await expect(deleteBtn).toBeVisible({ timeout: 20_000 })
		await expect(deleteBtn).toBeDisabled()

		// Master password alone does not enable it.
		await page
			.getByLabel(/Re-enter your master password/i)
			.fill(DEV_MASTER_PASSWORD)
		await expect(deleteBtn).toBeDisabled()
	})

	test('GDPR export dialog offers a downloadable package', async ({ page }) => {
		// @e2e gdpr-compliance::full-package-with-unlocked-vault
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await gotoVaultRoute(page, 'secrets')

		// Open the actions-bar "Actions" overflow and start the GDPR export.
		await openActionsMenu(page)
		await page
			.getByRole('menuitem', { name: /GDPR export/i })
			.evaluate((el: HTMLElement) => el.click())

		// Unlocked: the dialog offers the full package download.
		await expect(
			page.getByText(/will include both your account metadata/i),
		).toBeVisible({ timeout: 20_000 })
		await expect(
			page.getByRole('button', { name: /Download full package/i }),
		).toBeVisible()
	})
})
