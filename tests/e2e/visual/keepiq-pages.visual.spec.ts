import type { Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for Keepiq's routed page components.
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * WHAT THIS FILE IS FOR, AND THE TRAP IT IS WRITTEN AGAINST
 * ---------------------------------------------------------
 * Gate-26 (visual-coverage) requires every routed page component to have a
 * visual proof. It looks for the component's NAME anywhere under
 * `tests/e2e/visual/**` — which is satisfiable by writing the name in a
 * comment. Measured on this repo: a two-line file whose entire content was
 *
 *     // PROBE — a bare mention, in a comment, of PersonalActivityView.
 *
 * took gate-26 from 10 findings to 9. Nothing navigated, nothing asserted,
 * nothing was screenshotted.
 *
 * Therefore every test below goes through `shootComponent()`, which REQUIRES a
 * `data-testid` that only the component under test renders to be visible before
 * the shot is taken. Nextcloud paints its header, its navigation and the app
 * shell on every route, including the ones that bounced to the lock screen, so
 * "a screenshot was produced" is not evidence that the page was reached — the
 * assertion is.
 *
 * WHY THE ROUTES ARE REACHED THE WAY THEY ARE
 * -------------------------------------------
 * The router is in HASH mode, and the vault's private key lives only in memory.
 * A full `page.goto()` to an in-app route therefore reloads the SPA, discards
 * the CryptoKey and lands on the lock gate. The five authenticated pages are
 * reached with `gotoVaultRoute()` (an in-place `location.hash` change) after a
 * single `unlockVault()`; the three PUBLIC recipient pages
 * (`PUBLIC_ROUTE_NAMES` in `src/App.vue`) are exempt from the lock guard and
 * are reached with a plain `goto`.
 *
 * FIXTURES ARE CREATED BY THE TESTS THAT NEED THEM
 * ------------------------------------------------
 * `SeedDevelopmentLinkShares` does seed link shares, but the workflow suite
 * REVOKES them (`linkShare#destroy` is a hard delete) — observed on this rig:
 * `oc_doriath_link_shares` went from 3 rows to 0 across one full run of
 * `tests/e2e/workflows/`. A baseline that depends on another spec's leftovers
 * passes or fails according to test ORDER, so the link-share and ephemeral-send
 * tests below mint their own row first. The server stores those payloads as
 * opaque blobs (it never decrypts them), which is why a placeholder ciphertext
 * is enough to render the recipient's password prompt — the surface an external
 * recipient actually sees.
 */
import { expect, test } from '@playwright/test'
import {
	APP_BASE,
	gotoVaultRoute,
	unlockVault,
} from '../workflows/_workflow-helpers.ts'
import { shootComponent } from './_visual-helpers.ts'

/** A placeholder ciphertext blob. The server never decrypts it. */
const OPAQUE = 'dmlzdWFsLWJhc2VsaW5lLXBsYWNlaG9sZGVy'

/**
 * Call a Keepiq JSON API from inside the page, so the Nextcloud session cookie
 * and the CSRF request token both travel with the request.
 *
 * @param page   The Playwright page (any Keepiq route already loaded).
 * @param method HTTP method.
 * @param path   App-relative path, e.g. `/api/v1/sends`.
 * @param body   Optional JSON body.
 * @return The parsed response body.
 */
async function api(
	page: Page,
	method: string,
	path: string,
	body?: Record<string, unknown>,
): Promise<{ status: number; json: any }> {
	return await page.evaluate(
		async ({ method, path, body, base }) => {
			const head = document.querySelector('head[data-requesttoken]')
			const token =
				(head && head.getAttribute('data-requesttoken'))
				|| ((window as any).OC && (window as any).OC.requestToken)
				|| ''
			const res = await fetch(`${base}${path}`, {
				method,
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					requesttoken: token,
					'OCS-APIRequest': 'true',
				},
				body: body ? JSON.stringify(body) : undefined,
			})
			const text = await res.text()
			let parsed: any
			try {
				parsed = JSON.parse(text)
			} catch {
				parsed = { raw: text.slice(0, 300) }
			}
			return { status: res.status, json: parsed }
		},
		{ method, path, body: body ?? null, base: APP_BASE },
	)
}

test.describe('Keepiq — routed page baselines', () => {
	// ── The five authenticated pages ────────────────────────────────────────
	// One unlock per test: each test gets a fresh page, and the CryptoKey does
	// not survive a page. `test.describe.serial` is deliberately NOT used — it
	// would mask a failure by never running the tests after it.

	test('PersonalActivityView — /my-activity', async ({ page }) => {
		await unlockVault(page)
		await gotoVaultRoute(page, 'my-activity')
		await shootComponent(
			page,
			'personal-activity-view',
			'personal-activity-view.png',
		)
	})

	test('HealthReportView — /password-health', async ({ page }) => {
		await unlockVault(page)
		await gotoVaultRoute(page, 'password-health')
		await shootComponent(page, 'health-report', 'health-report-view.png')
	})

	test('CertificateInventoryView — /certificates', async ({ page }) => {
		await unlockVault(page)
		await gotoVaultRoute(page, 'certificates')
		await shootComponent(
			page,
			'certificate-inventory-view',
			'certificate-inventory-view.png',
		)
	})

	test('ApplicationRegisterView — /applications', async ({ page }) => {
		await unlockVault(page)
		await gotoVaultRoute(page, 'applications')
		await shootComponent(
			page,
			'application-register-view',
			'application-register-view.png',
		)
	})

	test('ApplicationDetail — /applications/:id', async ({ page }) => {
		await unlockVault(page)
		// Pick a real application rather than hardcoding a seeded uuid: the ids
		// are deterministic per install but not across installs.
		const apps = await api(page, 'GET', '/api/v1/applications')
		expect(apps.status, `GET /api/v1/applications -> ${apps.status}`).toBe(200)
		expect(
			Array.isArray(apps.json) && apps.json.length > 0,
			'no application to open a detail page for — is the dev seed present?',
		).toBe(true)
		await gotoVaultRoute(page, `applications/${apps.json[0].id}`)
		await shootComponent(page, 'application-detail', 'application-detail.png')
	})

	// ── The three PUBLIC recipient pages ────────────────────────────────────
	// Exempt from the lock guard (src/App.vue PUBLIC_ROUTE_NAMES), so they are
	// reached with a plain goto and render without an unlocked vault. Each one
	// is baselined in its FIRST recipient state — the password prompt — which is
	// what an external recipient sees when they open the link.

	test('SecretRequestFill — /share/request/:token', async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		const reqs = await api(page, 'GET', '/api/v1/secret-requests')
		expect(reqs.status, `GET /api/v1/secret-requests -> ${reqs.status}`).toBe(
			200,
		)
		const pending = (Array.isArray(reqs.json) ? reqs.json : []).find(
			(r: any) => r.status === 'pending',
		)
		expect(pending, 'no pending secret request to fill').toBeTruthy()
		await page.goto(`${APP_BASE}/share/request/${pending.token}`, {
			waitUntil: 'domcontentloaded',
		})
		await shootComponent(page, 'secret-request-fill', 'secret-request-fill.png')
	})

	test('LinkShareAccess — /share/link/:token', async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		const secrets = await api(page, 'GET', '/api/v1/secrets?limit=1')
		expect(secrets.status, `GET /api/v1/secrets -> ${secrets.status}`).toBe(200)
		const secret = (secrets.json?.items ?? [])[0]
		expect(secret, 'no secret to share — is the dev seed present?').toBeTruthy()
		const created = await api(
			page,
			'POST',
			`/api/v1/secrets/${secret.id}/link-shares`,
			{
				encryptedSecretSnapshot: OPAQUE,
				argon2idSalt: OPAQUE,
				usageLimit: 5,
			},
		)
		expect(
			created.status,
			`POST link-share -> ${created.status} ${JSON.stringify(created.json).slice(0, 200)}`,
		).toBeLessThan(300)
		const token = created.json.token ?? created.json.data?.token
		expect(token, 'the created link share carries no token').toBeTruthy()
		await page.goto(`${APP_BASE}/share/link/${token}`, {
			waitUntil: 'domcontentloaded',
		})
		await shootComponent(page, 'link-share-access', 'link-share-access.png')
	})

	test('EphemeralSendAccess — /send/:token', async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		const created = await api(page, 'POST', '/api/v1/sends', {
			encryptedPayload: OPAQUE,
			payloadType: 'text',
			maxViews: 5,
			ttlSeconds: 3600,
			hasPassword: true,
			wrappedKey: OPAQUE,
			argon2idSalt: OPAQUE,
		})
		expect(
			created.status,
			`POST /api/v1/sends -> ${created.status} ${JSON.stringify(created.json).slice(0, 200)}`,
		).toBeLessThan(300)
		const token = created.json.token ?? created.json.data?.token
		expect(token, 'the created ephemeral send carries no token').toBeTruthy()
		await page.goto(`${APP_BASE}/send/${token}`, {
			waitUntil: 'domcontentloaded',
		})
		await shootComponent(page, 'send-access-page', 'ephemeral-send-access.png')
	})
})
