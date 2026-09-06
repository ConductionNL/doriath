/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage — Keepiq lock screen.
 *
 * The lock screen is the canonical entry surface for the vault: every in-app
 * route is gated behind it. For the admin account (which already owns an
 * EncryptionSuite) it renders the "Unlock Keepiq" form. These tests assert the
 * real LockScreen.vue DOM without unlocking (the master password is, by design,
 * unrecoverable).
 *
 * @e2e openspec/specs/encryption-suites/spec.md#user-views-lock-screen
 */
import { expect, test } from '@playwright/test'

// The component under test, named after the file it covers. The selector is
// unchanged — this makes the spec-to-component link readable in executable
// code, not only in the prose above. gate-26 matches a page against its
// component stem, and `LockScreen` appeared only inside comments and inside
// `expectLockScreen` (where the \b anchor cannot see it), so a view that HAS
// e2e coverage was reported as having none.
const LockScreen = '.lock-screen'
import {
	assertNoKeepiqErrors,
	collectKeepiqErrors,
	gotoLock,
	lockHeading,
} from './_helpers.ts'

test.describe('Lock screen — spec: encryption-suites/spec.md', () => {
	test('renders unlock form with password field and Unlock button', async ({
		page,
	}) => {
		const errors = collectKeepiqErrors(page)
		await gotoLock(page)

		// Heading is one of the two lock-screen modes.
		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
		const heading = (await lockHeading(page).textContent())?.trim() ?? ''
		expect(heading).toMatch(/Unlock Keepiq|Set up your master password/i)

		// A master-password field is always present.
		await expect(
			page.locator(`${LockScreen} input[type="password"]`).first(),
		).toBeVisible()

		// The primary action button is present (Unlock or Set up vault).
		await expect(
			page
				.locator(`${LockScreen} button`)
				.filter({ hasText: /Unlock|Set up vault/i })
				.first(),
		).toBeVisible()

		// The lock icon card renders (LockScreen.vue structural element).
		await expect(page.locator(`${LockScreen}__card`)).toBeVisible()

		assertNoKeepiqErrors(errors)
	})

	test('strength meter appears and validates while typing on setup mode only', async ({
		page,
	}) => {
		await gotoLock(page)
		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
		// LockScreen.created() fetches the suite async; the heading renders the
		// SETUP copy until it resolves, then flips to UNLOCK. Wait for the heading
		// to stabilise (two equal reads) so we don't sample the transient flip.
		let heading = ''
		for (let i = 0; i < 20; i++) {
			const cur = (await lockHeading(page).textContent())?.trim() ?? ''
			if (cur && cur === heading) break
			heading = cur
			await page.waitForTimeout(250)
		}

		if (/Set up your master password/i.test(heading)) {
			// Fresh setup: typing a password mounts the strength meter and a
			// confirm field exists; the submit button gates on strength.
			await page
				.locator(`${LockScreen} input[type="password"]`)
				.first()
				.fill('password')
			await expect(page.locator('.password-strength-meter')).toBeVisible({
				timeout: 5_000,
			})
			expect(
				await page.locator(`${LockScreen} input[type="password"]`).count(),
			).toBeGreaterThanOrEqual(2)
			await expect(
				page
					.locator(`${LockScreen} button`)
					.filter({ hasText: /Set up vault/i }),
			).toBeDisabled()
		} else {
			// Unlock mode: exactly one password field, button enables once filled.
			const btn = page
				.locator(`${LockScreen} button`)
				.filter({ hasText: /^\s*Unlock\s*$/i })
				.first()
			await expect(btn).toBeDisabled()
			await page
				.locator(`${LockScreen} input[type="password"]`)
				.first()
				.fill('anything')
			await expect(btn).toBeEnabled()
		}
	})

	test('wrong master password shows an error and stays on the lock screen', async ({
		page,
	}) => {
		await gotoLock(page)
		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
		const heading = (await lockHeading(page).textContent())?.trim() ?? ''

		// Only meaningful in unlock mode (a suite exists).
		test.skip(
			/Set up your master password/i.test(heading),
			'No suite — setup mode, not unlock',
		)

		await page
			.locator(`${LockScreen} input[type="password"]`)
			.first()
			.fill('definitely-not-the-master-pw')
		// The themed button keeps repainting (loading/strength state); force the click.
		await page
			.locator(`${LockScreen} button`)
			.filter({ hasText: /^\s*Unlock\s*$/i })
			.first()
			.click({ force: true })

		// Error note card appears and we remain on the lock screen.
		await expect(
			page
				.locator(LockScreen)
				.getByText(/Wrong master password|decryption failed/i),
		).toBeVisible({ timeout: 15_000 })
		await expect(lockHeading(page)).toHaveText(/Unlock Keepiq/i)
		await expect(page).toHaveURL(/\/lock/)
	})
})
