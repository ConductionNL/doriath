/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage — Keepiq gated routes (Dashboard, Vault, Features &
 * roadmap, Secret detail).
 *
 * When the vault is locked, the router guard redirects every in-app route to
 * the lock screen with a returnUrl. This is the observable, data-independent UI
 * contract for these routes for a locked user. (Reaching the rendered views
 * requires the master password, which is — by design — unrecoverable, so the
 * redirect behaviour is what we assert.)
 *
 * @e2e openspec/specs/dashboard/spec.md#dashboard-route-gated-by-lock
 * @e2e openspec/specs/secrets/spec.md#vault-route-gated-by-lock
 * @e2e openspec/specs/secrets/spec.md#secret-detail-route-gated-by-lock
 * @e2e openspec/specs/secrets/spec.md#folder-route-gated-by-lock
 */
import { expect, test } from '@playwright/test'
import {
	assertNoKeepiqErrors,
	collectKeepiqErrors,
	gotoRoute,
	lockHeading,
} from './_helpers.ts'

const ROUTES = [
	{ name: 'Dashboard', hash: '#/' },
	{ name: 'Vault', hash: '#/secrets' },
	{ name: 'Features & roadmap', hash: '#/features-roadmap' },
	// Secret detail (/secrets/:id) and a folder-filtered vault (/folders/:folderId)
	// are parametrised routes; a locked user is redirected to the lock gate for any
	// id/folder value, so the placeholder ids below exercise the guard without
	// depending on a real (decryptable) secret or folder existing.
	{ name: 'Secret detail', hash: '#/secrets/placeholder-id' },
	{ name: 'Vault folder', hash: '#/folders/placeholder-folder' },
] as const

test.describe('Gated routes — router guard redirects to lock', () => {
	for (const route of ROUTES) {
		test(`${route.name} (${route.hash}) redirects a locked user to the lock screen`, async ({
			page,
		}) => {
			const errors = collectKeepiqErrors(page)
			await gotoRoute(page, route.hash)

			// The guard sends us to /lock (preserving the intended route in returnUrl).
			await expect(page).toHaveURL(/\/lock/, { timeout: 15_000 })
			await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
			await expect(lockHeading(page)).toHaveText(
				/Unlock Keepiq|Set up your master password/i,
			)

			assertNoKeepiqErrors(errors)
		})
	}

	test('app root resolves into the SPA and lands on the lock gate', async ({
		page,
	}) => {
		const errors = collectKeepiqErrors(page)
		await page.goto('/index.php/apps/keepiq/', {
			waitUntil: 'domcontentloaded',
		})

		// The SPA shell mounts and the lock gate is shown.
		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
		await expect(page).toHaveURL(/\/lock/)

		assertNoKeepiqErrors(errors)
	})
})
