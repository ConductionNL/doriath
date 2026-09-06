import type { Browser, BrowserContext, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ANONYMOUS recipient — the person a share/send/fill link is actually
 * for, opening it with no Nextcloud session at all.
 *
 * Nothing else in the suite covers this. `page-surfaces.spec.ts` drives the
 * same three recipient components, but on the AUTHENTICATED shell with the
 * admin cookie jar, so it could never catch the bug that shipped: the link
 * builders emitted hash URLs (`/public#/share/link/<token>`) that the
 * createWebHistory router never reads, and every recipient without an
 * account landed on the lock screen being asked for a master password they
 * do not have. The account-less browser context below is the point of this
 * file.
 *
 * Two link generations are asserted:
 *   - the PATH form the builders emit now (`/public/share/link/<token>`),
 *     served by publicShell#pageCatchAll and resolved by the router base;
 *   - the RETIRED hash form, which sits unregenerable in recipients'
 *     inboxes and must keep working through the bootstrap handoff —
 *     including the fragment-mode send key, which must stay in the URL
 *     fragment where the server cannot see it.
 *
 * Fixtures are minted through the authenticated `page` fixture (the server
 * stores only opaque blobs, so no real crypto is needed); each test then
 * opens the link in a context with an EMPTY storage state.
 */
import { expect, test } from '@playwright/test'
import { APP_BASE } from './_workflow-helpers.ts'

/** A placeholder ciphertext blob. The server stores it opaquely, never decrypts. */
const OPAQUE = 'ZTJlLXB1YmxpYy1yZWNpcGllbnQtcGxhY2Vob2xkZXI='

/**
 * Call a Keepiq JSON API from inside the authenticated page so the session
 * cookie and the CSRF request token both travel with the request.
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
 * Land the authenticated page somewhere cheap that carries the CSRF token,
 * so the fixture-minting api() calls have a page to run in (see the
 * identical helper in page-surfaces.spec.ts for why NOT the app root).
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
 * Mint a link share on the first available secret and return its token.
 */
async function mintLinkShareToken(page: Page): Promise<string> {
	const secrets = await api(page, 'GET', '/api/v1/secrets?limit=1')
	expect(secrets.status, `GET /api/v1/secrets -> ${secrets.status}`).toBe(200)
	const secret = (secrets.json?.items ?? [])[0]
	expect(secret, 'no secret to share — the dev seed is missing').toBeTruthy()
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
	return token
}

/**
 * A browser context with NO cookies and NO storage — the recipient.
 *
 * The config's `use.storageState` injects the admin cookie jar into every
 * default context; an explicit empty state overrides it. Callers must close
 * the context.
 */
async function anonymousPage(
	browser: Browser,
): Promise<{ context: BrowserContext; page: Page }> {
	const context = await browser.newContext({
		storageState: { cookies: [], origins: [] },
	})
	return { context, page: await context.newPage() }
}

/**
 * The recipient-side assertions every test here shares: the component
 * rendered, and NEITHER lock/setup surface did. The second half is the bug:
 * the failure mode was precisely a lock screen with "Could not determine
 * whether your vault is already set up" shown to someone with no vault.
 */
async function expectRecipientSurface(page: Page, testId: string): Promise<void> {
	await expect(
		page.locator(`[data-testid="${testId}"]`),
		`[data-testid="${testId}"] did not render — the recipient route was not reached`,
	).toBeVisible({ timeout: 25_000 })
	await expect(
		page.locator('.lock-screen'),
		'the lock gate is on screen — the recipient was asked for a master password',
	).toHaveCount(0)
}

test.describe('Anonymous recipient — no Nextcloud session', () => {
	test('opens a link share at the PATH form /public/share/link/:token', async ({
		page,
		browser,
	}) => {
		await landForFixtures(page)
		const token = await mintLinkShareToken(page)

		const anon = await anonymousPage(browser)
		try {
			await anon.page.goto(`${APP_BASE}/public/share/link/${token}`, {
				waitUntil: 'domcontentloaded',
			})
			await expectRecipientSurface(anon.page, 'link-share-access')
			// Phase 1 of the recipient protocol: the passphrase prompt, not an
			// error — the salt was supplied at minting time.
			await expect(
				anon.page.locator('[data-testid="link-share-load-error"]'),
			).toHaveCount(0)
			await expect(
				anon.page.locator('[data-testid="link-share-password"]'),
			).toBeVisible({ timeout: 20_000 })
		} finally {
			await anon.context.close()
		}
	})

	test('a RETIRED hash link (/public#/share/link/:token) still reaches the share', async ({
		page,
		browser,
	}) => {
		await landForFixtures(page)
		const token = await mintLinkShareToken(page)

		const anon = await anonymousPage(browser)
		try {
			// The exact string sitting in recipients' inboxes.
			await anon.page.goto(`${APP_BASE}/public#/share/link/${token}`, {
				waitUntil: 'domcontentloaded',
			})
			await expectRecipientSurface(anon.page, 'link-share-access')
			// The bootstrap handoff rewrote the URL to the path form in place.
			await expect
				.poll(() => anon.page.url())
				.toContain(`/public/share/link/${token}`)
		} finally {
			await anon.context.close()
		}
	})

	test('a RETIRED fragment-mode send link keeps its content key out of the query', async ({
		page,
		browser,
	}) => {
		await landForFixtures(page)
		const created = await api(page, 'POST', '/api/v1/sends', {
			encryptedPayload: OPAQUE,
			payloadType: 'text',
			maxViews: 5,
			ttlSeconds: 3600,
			hasPassword: false,
		})
		expect(
			created.status,
			`POST /api/v1/sends -> ${created.status} ${JSON.stringify(created.json).slice(0, 200)}`,
		).toBeLessThan(300)
		const token = created.json.token ?? created.json.data?.token
		expect(token, 'the created ephemeral send carries no token').toBeTruthy()

		const anon = await anonymousPage(browser)
		try {
			// Legacy shape: the key rides INSIDE the old SPA fragment.
			await anon.page.goto(
				`${APP_BASE}/public#/send/${token}?k=e2e-placeholder-key`,
				{ waitUntil: 'domcontentloaded' },
			)
			await expectRecipientSurface(anon.page, 'send-access-page')
			// The handoff moved the key to a NEW fragment — never into the real
			// query string, which the browser would transmit on the next load.
			await expect
				.poll(() => anon.page.url())
				.toContain('#k=e2e-placeholder-key')
			expect(new URL(anon.page.url()).search).not.toContain('k=')
			// No password on this send, so the reveal button is offered directly.
			await expect(
				anon.page.locator('[data-testid="send-access-open"]'),
			).toBeVisible({ timeout: 20_000 })
			await expect(
				anon.page.locator('[data-testid="send-access-password"]'),
			).toHaveCount(0)
		} finally {
			await anon.context.close()
		}
	})

	test('opens a secret-request fill link at /public/share/request/:token', async ({
		page,
		browser,
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

		const anon = await anonymousPage(browser)
		try {
			await anon.page.goto(
				`${APP_BASE}/public/share/request/${pending.token}`,
				{ waitUntil: 'domcontentloaded' },
			)
			await expectRecipientSurface(anon.page, 'secret-request-fill')
			await expect(anon.page.locator('[data-testid="fill-form"]')).toBeVisible(
				{ timeout: 20_000 },
			)
		} finally {
			await anon.context.close()
		}
	})
})
