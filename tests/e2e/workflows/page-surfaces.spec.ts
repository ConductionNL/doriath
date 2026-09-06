import type { Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every routed page component of Keepiq, driven in a real browser.
 *
 * WHY THIS FILE IS UNDER workflows/ AND NOT UNDER visual/
 * -------------------------------------------------------
 * Gate-26 (visual-coverage) offers three remedies for a page component with no
 * proof: a visual baseline under `tests/e2e/visual/**`, an e2e test anywhere
 * under `tests/e2e/**`, or an `@visual exclude`. The FIRST of those is a
 * directory this repository's CI never executes — issue #198, re-measured while
 * writing this file:
 *
 *     npx playwright test --config=tests/e2e/playwright.config.ts --list
 *       -> Total: 56 tests in 14 files        (the config CI loads)
 *     npx playwright test --config=playwright.config.ts --list
 *       -> Total: 65 tests in 16 files        (the root config, local only)
 *
 * The CI config declares only a `chromium` project and that project carries
 * `testIgnore: ['**\/visual/**']`, so the visual specs are ignored by the only
 * project that exists. Adding ten PNG baselines there would turn gate-26 green
 * with nothing ever running — a check that cannot fail.
 *
 * So the load-bearing coverage lives HERE, in the suite CI actually runs. The
 * visual baselines in `tests/e2e/visual/keepiq-pages.visual.spec.ts` are a
 * local-only extra; gate-26 is green because of this file, and that was
 * verified by deleting the visual spec and re-running the gate.
 *
 * WHAT EACH TEST HAS TO PROVE
 * ---------------------------
 * That the component RENDERED — not that a page rendered. Nextcloud paints its
 * header, navigation and app shell on every route including the ones that
 * bounced to the lock gate, and the vault's lock guard is exactly the thing
 * that silently redirects. Every test therefore asserts:
 *
 *   1. the component's own `data-testid` root, and
 *   2. a piece of copy or a control that ONLY that component renders, and
 *   3. that the lock screen is NOT present (for the authenticated pages).
 *
 * (2) is what separates this from a chrome assertion. The fleet-wide version of
 * that mistake is asserting a widget's TITLE, which is host chrome and passes
 * even when the body never renders.
 *
 * ROUTING NOTE
 * ------------
 * The vault's private key lives only in memory, so a full `page.goto()` to an
 * in-app route reloads the SPA, discards the CryptoKey and lands on the lock
 * gate. Authenticated pages are reached with `gotoVaultRoute()` (an in-place
 * router navigation) after one `unlockVault()`. The three public recipient
 * routes are exempt from the lock guard (`PUBLIC_ROUTE_NAMES` in `src/App.vue`)
 * and are reached with `goto`.
 */
import { expect, test } from '@playwright/test'
import { APP_BASE, gotoVaultRoute, unlockVault } from './_workflow-helpers.ts'

/** A placeholder ciphertext blob. The server stores it opaquely, never decrypts. */
const OPAQUE = 'ZTJlLXBhZ2Utc3VyZmFjZS1wbGFjZWhvbGRlcg=='

/**
 * Call a Keepiq JSON API from inside the page so the session cookie and the
 * CSRF request token both travel with the request.
 *
 * @param page   The Playwright page, with a Keepiq route already loaded.
 * @param method HTTP method.
 * @param path   App-relative path, e.g. `/api/v1/sends`.
 * @param body   Optional JSON body.
 * @return `{ status, json }` — the status is returned so a probe can never read
 *         a 403 body as an empty success.
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

/**
 * Land somewhere cheap that still carries the CSRF request token, so the
 * fixture-minting `api()` calls below have a page to run in.
 *
 * ⚠️ NOT the app root. `${APP_BASE}/` is the dashboard, which bounces a locked
 * session to the lock gate and then loads every dashboard widget; the main
 * thread is busy for long enough that the very first `page.evaluate()` can blow
 * a 45 s test timeout. Measured: these three tests ran in ~7 s each in
 * isolation and two of them timed out at 52 s / 46 s in a full-suite run doing
 * exactly that. `/lock` renders one form and nothing else. The token is
 * server-rendered into `<head>`, so waiting for it also proves the page is
 * responsive before anything is asked of it.
 *
 * @param page The Playwright page.
 */
async function landForFixtures(page: Page): Promise<void> {
	await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
	await expect
		.poll(
			async () =>
				await page.evaluate(() => {
					const head = document.querySelector('head[data-requesttoken]')
					return (head && head.getAttribute('data-requesttoken')) || ''
				}),
			{ message: 'no CSRF request token on the page', timeout: 20_000 },
		)
		.not.toBe('')
}

/**
 * Assert the component root is on screen and that we did NOT land on the lock
 * gate. Both halves matter: the lock screen renders inside the same app shell,
 * so "something rendered" is not evidence the route was reached.
 *
 * @param page   The Playwright page.
 * @param testId The `data-testid` only this component renders.
 */
async function expectComponentMounted(page: Page, testId: string): Promise<void> {
	await expect(
		page.locator(`[data-testid="${testId}"]`),
		`[data-testid="${testId}"] did not render — the route was not reached`,
	).toBeVisible({ timeout: 25_000 })
	await expect(
		page.locator('.lock-screen'),
		'the lock gate is on screen, so the route redirected',
	).toHaveCount(0)
}

test.describe('Routed page surfaces — authenticated', () => {
	test('PersonalActivityView renders the personal audit trail at #/my-activity', async ({
		page,
	}) => {
		await unlockVault(page)
		await gotoVaultRoute(page, 'my-activity')
		await expectComponentMounted(page, 'personal-activity-view')
		// Component-specific, not chrome: this heading exists on no other route.
		await expect(
			page
				.locator('[data-testid="personal-activity-view"]')
				.getByText('My activity', { exact: true }),
		).toBeVisible()
		// The trail is either populated or explicitly empty — both are the
		// component's own rendering, and a third outcome (neither) would mean
		// the body never resolved.
		const rows = page.locator('[data-testid="personal-activity-item"]')
		const empty = page.locator('[data-testid="personal-activity-empty"]')
		await expect
			.poll(async () => (await rows.count()) + (await empty.count()), {
				message: 'neither activity rows nor the empty state rendered',
			})
			.toBeGreaterThan(0)
	})

	test('HealthReportView renders the password-health controls at #/password-health', async ({
		page,
	}) => {
		await unlockVault(page)
		await gotoVaultRoute(page, 'password-health')
		await expectComponentMounted(page, 'health-report')
		await expect(
			page
				.locator('[data-testid="health-report"]')
				.getByText('Password health'),
		).toBeVisible()
		// The unlocked view must render its own controls, not the locked
		// placeholder. `staleness-select` is unconditional on the unlocked
		// branch; `breach-optin` is NOT — it is gated on the admin
		// `breachGateOn` setting, which is off by default, so asserting it here
		// fails on a stock instance. (It did, on the first run of this file.)
		await expect(
			page.locator('[data-testid="health-report-locked"]'),
		).toHaveCount(0)
		await expect(page.locator('[data-testid="staleness-select"]')).toBeVisible()
		// The analysis runs client-side over the unlocked vault, so the body
		// must reach one of its two terminal states. Neither appearing means the
		// component mounted its shell and never ran — which is what a broken
		// unlock hand-off looks like, and is exactly what this must not pass on.
		await expect(
			page
				.locator(
					'[data-testid="health-score"], [data-testid="health-report-analysing"]',
				)
				.first(),
			'password-health rendered neither a score nor an "analysing" state',
		).toBeVisible({ timeout: 25_000 })
	})

	test('CertificateInventoryView renders the stored-certificate section at #/certificates', async ({
		page,
	}) => {
		// @e2e certificate-lifecycle::owner-lists-their-certificates
		// Scenario: GIVEN a user owns certificate-type secrets and an active
		// EncryptionSuite, WHEN they request the inventory, THEN the entries MUST
		// come back tagged `client_parsed` (stored) or `server_parsed` (CA-issued)
		// with NO private key or ciphertext.
		await unlockVault(page)
		await gotoVaultRoute(page, 'certificates')
		await expectComponentMounted(page, 'certificate-inventory-view')
		await expect(
			page
				.locator('[data-testid="certificate-inventory-view"]')
				.getByText('Certificates')
				.first(),
		).toBeVisible()
		await expect(
			page.locator('[data-testid="cert-stored-section"]'),
		).toBeVisible()

		const inv = await api(page, 'GET', '/api/v1/certificates/inventory')
		expect(
			inv.status,
			`GET /api/v1/certificates/inventory -> ${inv.status}`,
		).toBe(200)
		const entries = [
			...(inv.json.stored ?? []),
			...(inv.json.suites ?? []),
			...(inv.json.ca ?? []),
		]
		// The GIVEN, asserted rather than assumed: a zero-entry inventory would
		// satisfy every "no private key present" check below for free.
		expect(
			(inv.json.stored ?? []).length,
			'no certificate-type secret in the vault — the GIVEN does not hold, so '
				+ 'the tagging assertions below could not fail',
		).toBeGreaterThan(0)
		expect(
			(inv.json.suites ?? []).length,
			'no active EncryptionSuite in the inventory — the GIVEN does not hold',
		).toBeGreaterThan(0)
		for (const e of entries) {
			expect(
				['client_parsed', 'server_parsed'],
				`entry ${e.id ?? e.caRole} has metadataSource "${e.metadataSource}"`,
			).toContain(e.metadataSource)
		}
		// …and no private key or ciphertext rides along with the metadata.
		const raw = JSON.stringify(inv.json)
		expect(raw, 'the inventory leaked a PEM private key').not.toMatch(
			/PRIVATE KEY/,
		)
		for (const key of [
			'privateKey',
			'private_key',
			'key',
			'ciphertext',
			'encryptedKey',
		]) {
			expect(raw, `the inventory carries a "${key}" field`).not.toContain(
				`"${key}"`,
			)
		}
	})

	test('the Applications entry lives in the settings foldout, not the main navigation', async ({
		page,
	}) => {
		// @e2e menu-architecture::applications-entry-appears-in-the-settings-foldout-not-main-nav
		// `src/menu-layout.json` lifts "ApplicationsMenu" into `settingsSection`,
		// which NC renders as `NcAppNavigationSettings` — the gear foldout that
		// sits OUTSIDE the scrollable nav list. Both halves are asserted: absent
		// from the scrollable list, present in the foldout. Asserting only the
		// absence would pass on a nav that failed to render at all.
		await unlockVault(page)
		await gotoVaultRoute(page, '')

		const nav = page.locator('.app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 20_000 })
		// The foldout NC renders for `settingsSection` is `.cn-app-nav__settings-list`;
		// everything else under `.app-navigation` is the scrollable list.
		const FOLDOUT = '.cn-app-nav__settings-list'
		await expect(
			nav.locator(FOLDOUT),
			'the settings foldout is not rendered at all',
		).toHaveCount(1, { timeout: 20_000 })

		const placement = await page.evaluate((foldoutSel) => {
			const nav = document.querySelector('.app-navigation')
			const out: Record<string, string> = {}
			for (const a of Array.from(nav?.querySelectorAll('a') ?? [])) {
				const label = (a.textContent || '').trim()
				if (!label) {
					continue
				}
				out[label] = a.closest(foldoutSel) ? 'foldout' : 'main'
			}
			return out
		}, FOLDOUT)

		// The positive control comes first: if the nav did not render its
		// daily-use entries, every "is not in the main nav" claim below would be
		// satisfied for free by an empty page.
		expect(
			placement.Dashboard,
			`the nav did not render its daily-use entries (saw: ${JSON.stringify(placement)})`,
		).toBe('main')
		expect(placement['All secrets']).toBe('main')
		// …and the relocated entries are in the foldout, not the main list. Both
		// halves: "not in main" alone would also pass if it vanished entirely.
		expect(
			placement.Applications,
			`"Applications" should be in the settings foldout (saw: ${JSON.stringify(placement)})`,
		).toBe('foldout')
		// Restyle Stage 1: Certificates and Emergency access moved from the
		// footer to the settings foldout (RESTYLE-PLAN.md).
		expect(placement.Certificates).toBe('foldout')
		expect(placement['Emergency access']).toBe('foldout')
	})

	test('ApplicationRegisterView lists the caller’s applications at #/applications', async ({
		page,
	}) => {
		// @e2e menu-architecture::relocated-route-stays-reachable
		// Scenario: the ApplicationsMenu entry moved out of the main nav into the
		// settings foldout, and the route itself is unchanged — so a DIRECT DEEP
		// LINK must still render ApplicationRegister. That is exactly what this
		// does: it never touches the menu, it addresses the route.
		await unlockVault(page)
		await gotoVaultRoute(page, 'applications')
		await expectComponentMounted(page, 'application-register-view')
		// The seed registers three applications for admin; the list must show
		// them by name rather than an empty state.
		const apps = await api(page, 'GET', '/api/v1/applications')
		expect(apps.status, `GET /api/v1/applications -> ${apps.status}`).toBe(200)
		expect(
			Array.isArray(apps.json) && apps.json.length > 0,
			'no application registered — the dev seed is missing, so this assertion could not fail',
		).toBe(true)
		await expect(
			page
				.locator('[data-testid="application-register-view"]')
				.getByText(apps.json[0].name, { exact: false })
				.first(),
		).toBeVisible({ timeout: 20_000 })
	})

	test('ApplicationDetail opens one application at #/applications/:id', async ({
		page,
	}) => {
		await unlockVault(page)
		const apps = await api(page, 'GET', '/api/v1/applications')
		expect(apps.status, `GET /api/v1/applications -> ${apps.status}`).toBe(200)
		const app = (Array.isArray(apps.json) ? apps.json : [])[0]
		expect(app, 'no application to open').toBeTruthy()
		await gotoVaultRoute(page, `applications/${app.id}`)
		await expectComponentMounted(page, 'application-detail')
		// The detail page must show THIS application, not a generic shell.
		await expect(
			page
				.locator('[data-testid="application-detail"]')
				.getByText(app.name, { exact: false })
				.first(),
		).toBeVisible({ timeout: 20_000 })
		// And its own secrets panel, which no other route renders.
		await expect(
			page.locator('[data-testid="application-secrets-panel"]'),
		).toBeVisible()
	})
})

test.describe('Routed page surfaces — public recipient routes', () => {
	test('SecretRequestFill renders the fill form at #/share/request/:token', async ({
		page,
	}) => {
		await landForFixtures(page)
		const reqs = await api(page, 'GET', '/api/v1/secret-requests')
		expect(reqs.status, `GET /api/v1/secret-requests -> ${reqs.status}`).toBe(
			200,
		)
		const pending = (Array.isArray(reqs.json) ? reqs.json : []).find(
			(r: any) => r.status === 'pending',
		)
		expect(
			pending,
			'no pending secret request — the dev seed is missing',
		).toBeTruthy()
		await page.goto(`${APP_BASE}/share/request/${pending.token}`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(
			page.locator('[data-testid="secret-request-fill"]'),
		).toBeVisible({ timeout: 25_000 })
		// The public route is exempt from the lock guard: an external recipient
		// has no vault, so a lock screen here would make the flow unusable.
		await expect(page.locator('.lock-screen')).toHaveCount(0)
		// The form itself, keyed on the fields the request asked for.
		await expect(page.locator('[data-testid="fill-form"]')).toBeVisible({
			timeout: 20_000,
		})
		for (const field of pending.requestedFields as string[]) {
			await expect(
				page.locator(`[data-testid="fill-field-${field}"]`),
				`the request asked for "${field}" but the form offers no input for it`,
			).toBeVisible()
		}
	})

	test('LinkShareAccess prompts an external recipient at #/share/link/:token', async ({
		page,
	}) => {
		await landForFixtures(page)
		const secrets = await api(page, 'GET', '/api/v1/secrets?limit=1')
		expect(secrets.status, `GET /api/v1/secrets -> ${secrets.status}`).toBe(200)
		const secret = (secrets.json?.items ?? [])[0]
		expect(secret, 'no secret to share — the dev seed is missing').toBeTruthy()
		// Mint the fixture rather than relying on the seeded rows: the
		// link-share workflow spec REVOKES them (hard delete), so depending on
		// them makes this test a function of run order. Observed on a dev rig:
		// oc_doriath_link_shares went 3 rows -> 0 across one suite run.
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
		await expect(page.locator('[data-testid="link-share-access"]')).toBeVisible({
			timeout: 25_000,
		})
		await expect(page.locator('.lock-screen')).toHaveCount(0)
		// Phase 1 of the recipient protocol: the password prompt. The salt was
		// supplied above, so a load error here is a real regression.
		await expect(
			page.locator('[data-testid="link-share-load-error"]'),
		).toHaveCount(0)
		await expect(page.locator('[data-testid="link-share-form"]')).toBeVisible({
			timeout: 20_000,
		})
		await expect(
			page.locator('[data-testid="link-share-password"]'),
		).toBeVisible()
	})

	test('EphemeralSendAccess prompts an external recipient at #/send/:token', async ({
		page,
	}) => {
		await landForFixtures(page)
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
		await expect(page.locator('[data-testid="send-access-page"]')).toBeVisible({
			timeout: 25_000,
		})
		await expect(page.locator('.lock-screen')).toHaveCount(0)
		await expect(page.locator('[data-testid="send-access-error"]')).toHaveCount(
			0,
		)
		// The send was created WITH a password, so the recipient must be asked
		// for one before anything is revealed.
		await expect(
			page.locator('[data-testid="send-access-password"]'),
		).toBeVisible({ timeout: 20_000 })
	})
})
