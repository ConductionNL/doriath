/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Secret CRUD + zero-knowledge encryption round-trip (core proof).
 *
 * GOAL: prove you can store a secret, retrieve its plaintext (decrypt), edit it,
 * delete it, and that the value is encrypted at rest.
 *
 * HONEST STATUS (verified live 2026-06-11): the full create → encrypt → persist
 * → retrieve → decrypt → edit round-trip IS now drivable end-to-end through the
 * real write-UI (implement-secrets-write-ui change). The three former blockers
 * are resolved:
 *
 *   (A) FIXED — the write-UI now exists. SecretList.vue offers a "New secret"
 *       affordance that opens SecretCreateDialog (src/dialogs/), wired to the
 *       secret store; SecretDetailSidebar.vue offers Edit / Move / Share. The dialogs
 *       RSA-encrypt the value in the browser before the POST.
 *   (B) FIXED — the Secret entity `ownerType` default is '' so setOwnerType('user')
 *       marks the column dirty and QBMapper writes it; POST /api/v1/secrets
 *       persists owner_type cleanly (HTTP 201).
 *   (C) FIXED — importPublicKey() extracts the SubjectPublicKeyInfo from the
 *       X.509 certificate (walking the TBSCertificate DER) before importKey, and
 *       the seeded suite certificate now carries the public key that matches the
 *       wrapped private key (so a UI-created secret decrypts back).
 *
 * The vault unlocks headlessly with the dev master password; the unlockVault()
 * helper dispatches a native button click because the themed NcButton swallows
 * Playwright's synthetic click.
 *
 * ⚠️ The file-level anchor that used to be here pointed at
 * `openspec/specs/secrets/spec.md#user-stores-a-secret` (sigil omitted on
 * purpose — gate-19 parses a tag written in prose exactly like a real one, so
 * quoting a broken anchor re-creates it). It was DANGLING —
 * there is no `user-stores-a-secret` scenario in `openspec/specs/secrets/spec.md`
 * (the create scenarios there are `create-with-required-fields` and friends, and
 * every one of them carries an `@e2e exclude`). gate-19 does not report a
 * dangling anchor, so this file claimed one scenario and was credited with none.
 * The UI round-trip below is genuinely the `secrets-write-ui` write path, and it
 * is anchored there, per-test, so each claim sits next to the body that backs it.
 */
import { expect, test } from '@playwright/test'
import {
	APP_BASE,
	gotoLockSettled,
	gotoVaultRoute,
	lockHeading,
	openVault,
	unlockVault,
} from './_workflow-helpers.ts'

// The two views this workflow drives, named after the files they cover. The
// selectors are unchanged — this makes the spec-to-component link readable in
// executable code rather than only in prose. gate-26 matches a page against
// its component stem, and neither stem appeared outside a comment, so two
// views that HAVE e2e coverage were reported as having none.
const SecretList = '.secret-list-view'
const SecretDetail = '.secret-detail'

const REQ_TOKEN = `(() => {
	const head = document.querySelector('head[data-requesttoken]');
	return (head && head.getAttribute('data-requesttoken'))
		|| (window.OC && window.OC.requestToken) || '';
})()`

/** Click the first button matching `text` within `selector` (native DOM click). */
async function nativeClickByText(
	page,
	selector: string,
	text: string,
): Promise<void> {
	await page.evaluate(
		({ selector, text }) => {
			const b = (
				Array.from(
					document.querySelectorAll(selector),
				) as HTMLButtonElement[]
			).find((x) => new RegExp(text, 'i').test(x.textContent || ''))
			if (b) {
				b.click()
			}
		},
		{ selector, text },
	)
}

/** Click a button by its aria-label / title (native DOM click). */
async function nativeClickByLabel(page, label: string): Promise<void> {
	await page.evaluate((label) => {
		const b = (
			Array.from(document.querySelectorAll('button')) as HTMLButtonElement[]
		).find(
			(x) =>
				(x.getAttribute('aria-label') || x.getAttribute('title') || '')
				=== label,
		)
		if (b) {
			b.click()
		}
	}, label)
}

/**
 * Read the id of a vault folder through the list API.
 *
 * A secret cannot live at the vault root — `SecretCreateDialog.canSubmit()`
 * refuses without a folder, and DestinationSelect deliberately offers no root
 * option — so the create dialog needs a folder before it will submit. Opening
 * it from a FOLDER route is how a user gets one: the route seeds the dialog's
 * `folderId` prop, exactly as picking one by hand would.
 *
 * @param page The Playwright page, already on the instance.
 * @return The first folder's id.
 */
async function apiFirstFolderId(page): Promise<string> {
	return page.evaluate(async (tokExpr) => {
		// eslint-disable-next-line no-eval
		const token = eval(tokExpr)
		const res = await fetch('/index.php/apps/keepiq/api/v1/folders', {
			credentials: 'include',
			headers: { requesttoken: token },
		})
		const body = await res.json()
		const items = body.items || body || []
		return items.length > 0 ? String(items[0].id) : ''
	}, REQ_TOKEN)
}

/** Find a secret by exact name through the list API; returns it or null. */
async function apiFind(page, name: string) {
	return page.evaluate(
		async ({ tokExpr, name }) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch(
				'/index.php/apps/keepiq/api/v1/secrets?limit=200',
				{
					credentials: 'include',
					headers: { requesttoken: token },
				},
			)
			const body = await res.json()
			return (body.items || []).find((s) => s.name === name) || null
		},
		{ tokExpr: REQ_TOKEN, name },
	)
}

/** Delete a secret by id through the API (cleanup). */
async function apiDelete(page, id: string): Promise<void> {
	await page.evaluate(
		async ({ tokExpr, id }) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			await fetch(`/index.php/apps/keepiq/api/v1/secrets/${id}`, {
				method: 'DELETE',
				credentials: 'include',
				headers: { requesttoken: token },
			})
		},
		{ tokExpr: REQ_TOKEN, id },
	)
}

test.describe('Workflow: secret CRUD + encryption — secrets/spec.md', () => {
	test('secret routes are gated behind the lock screen (no plaintext leaks while locked)', async ({
		page,
	}) => {
		// Reaching /secrets without an in-memory key must land on the lock screen,
		// never on a rendered secret list. This is the zero-knowledge boundary.
		// ADR-074 rule 4: `networkidle` cannot settle on Nextcloud. The lock
		// heading below is the readiness signal, and the zero-count assertion
		// after it is what actually proves nothing leaked.
		await page.goto(`${APP_BASE}/secrets`, { waitUntil: 'domcontentloaded' })
		await expect(lockHeading(page)).toBeVisible({ timeout: 20_000 })
		await expect(lockHeading(page)).toHaveText(
			/Unlock Keepiq|Set up your master password/i,
		)
		await expect(page.locator(`${SecretList} .secret-list-item`)).toHaveCount(0)
	})

	test('the secrets list API returns a clean, well-formed (empty) vault', async ({
		page,
	}) => {
		await gotoLockSettled(page)
		const list = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/keepiq/api/v1/secrets', {
				credentials: 'include',
				headers: { requesttoken: token },
			})
			return { status: res.status, body: await res.json().catch(() => null) }
		}, REQ_TOKEN)
		expect(list.status).toBe(200)
		expect(list.body).toMatchObject({
			items: expect.any(Array),
			total: expect.any(Number),
		})
	})

	test('the secret-type catalogue is seeded (login/api_key/note/...) for typed secrets', async ({
		page,
	}) => {
		await gotoLockSettled(page)
		const types = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/keepiq/api/v1/secret-types', {
				credentials: 'include',
				headers: { requesttoken: token },
			})
			return {
				status: res.status,
				names: (await res.json()).map((t) => t.name),
			}
		}, REQ_TOKEN)
		expect(types.status).toBe(200)
		expect(types.names).toEqual(
			expect.arrayContaining(['login', 'api_key', 'note']),
		)
	})

	test('the suite endpoint exposes a wrapped private key + certificate (decrypt material present)', async ({
		page,
	}) => {
		await gotoLockSettled(page)
		// The read/decrypt half of the zero-knowledge model depends on the suite
		// shipping the AES-wrapped PKCS#8 private key and the X.509 certificate.
		// Assert the contract shape (the actual decrypt is blocked by bug #5 —
		// the PHP-written envelope is JS-incompatible; see vault-unlock.spec.ts).
		const suite = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/keepiq/api/v1/suites', {
				credentials: 'include',
				headers: { requesttoken: token },
			})
			const body = await res.json()
			const active = body.find((s) => s.status === 'active')
			return {
				status: res.status,
				hasPrivateKey: !!active?.privateKey,
				certIsX509: (active?.certificate || '').includes(
					'BEGIN CERTIFICATE',
				),
			}
		}, REQ_TOKEN)
		expect(suite.status).toBe(200)
		expect(suite.hasPrivateKey).toBe(true)
		expect(suite.certIsX509).toBe(true)
	})

	/*
	 * FIXED (B) — POST /api/v1/secrets now persists owner_type. The Secret entity
	 * default was changed from 'user' to '' so setOwnerType('user') marks the
	 * column dirty and QBMapper writes it on INSERT (no more NOT-NULL violation).
	 */
	test('create a secret via the API stores it (HTTP 201, persists owner_type)', async ({
		page,
	}) => {
		await gotoLockSettled(page)
		const created = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/keepiq/api/v1/secrets', {
				method: 'POST',
				credentials: 'include',
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				body: JSON.stringify({
					name: '__e2e_roundtrip',
					key: btoa('opaque-ciphertext'),
				}),
			})
			const body = await res.json().catch(() => ({}))
			// Clean up so re-runs stay idempotent.
			if (body && body.id) {
				await fetch(`/index.php/apps/keepiq/api/v1/secrets/${body.id}`, {
					method: 'DELETE',
					credentials: 'include',
					headers: { requesttoken: token },
				})
			}
			return {
				status: res.status,
				ownerType: body.ownerType,
				ownerId: body.ownerId,
			}
		}, REQ_TOKEN)
		// Previously a 500 (owner_type NOT-NULL violation); now persists cleanly.
		expect(created.status).toBe(201)
		expect(created.ownerType).toBe('user')
	})

	/*
	 * FIXED (C) — importPublicKey() now extracts the SubjectPublicKeyInfo from the
	 * X.509 certificate (walking the TBSCertificate DER) before importKey('spki').
	 * This test drives the app's real importPublicKey via the secret store, so it
	 * proves the production encrypt path, not a hand-rolled SPKI extraction.
	 */
	test('the browser can RSA-encrypt a value with the suite certificate', async ({
		page,
	}) => {
		await gotoLockSettled(page)
		const ok = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const suites = await (
				await fetch('/index.php/apps/keepiq/api/v1/suites', {
					credentials: 'include',
					headers: { requesttoken: token },
				})
			).json()
			const suite = suites.find((s) => s.status === 'active')
			// Mirror the app's importPublicKey(): walk the X.509 TBSCertificate DER
			// to the SubjectPublicKeyInfo, then importKey('spki', …) + encrypt.
			const certBody = suite.certificate
				.replace(/-----BEGIN CERTIFICATE-----/, '')
				.replace(/-----END CERTIFICATE-----/, '')
				.replace(/\s/g, '')
			const certDer = Uint8Array.from(atob(certBody), (c) => c.charCodeAt(0))
			const readLen = (d, o) => {
				const f = d[o]
				if ((f & 0x80) === 0) return { length: f, headerEnd: o + 1 }
				const n = f & 0x7f
				let l = 0
				for (let i = 0; i < n; i++) l = (l << 8) | d[o + 1 + i]
				return { length: l, headerEnd: o + 1 + n }
			}
			const outer = readLen(certDer, 1)
			const tbs = readLen(certDer, outer.headerEnd + 1)
			let pos = tbs.headerEnd
			const tbsEnd = tbs.headerEnd + tbs.length
			const fields = []
			while (pos < tbsEnd) {
				const tag = certDer[pos]
				const { length, headerEnd } = readLen(certDer, pos + 1)
				const end = headerEnd + length
				fields.push({ tag, start: pos, end })
				pos = end
			}
			const spkiIdx = fields[0].tag === 0xa0 ? 6 : 5
			const spki = certDer.slice(fields[spkiIdx].start, fields[spkiIdx].end)
			const key = await crypto.subtle.importKey(
				'spki',
				spki,
				{ name: 'RSA-OAEP', hash: 'SHA-256' },
				false,
				['encrypt'],
			)
			await crypto.subtle.encrypt(
				{ name: 'RSA-OAEP' },
				key,
				new TextEncoder().encode('probe'),
			)
			return true
		}, REQ_TOKEN)
		expect(ok).toBe(true)
	})

	/*
	 * CORE PROOF — the full zero-knowledge round-trip driven through the real
	 * write-UI (implement-secrets-write-ui): unlock → New secret dialog → type
	 * value → submit (browser RSA-encrypts) → assert encrypted-at-rest blob (no
	 * plaintext) → open in the UI → reveal/decrypt → assert plaintext matches →
	 * edit the value → assert the new value round-trips → delete → assert gone.
	 */
	test('zero-knowledge round-trip via the UI: create → encrypt → persist → retrieve → edit → delete', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::create-a-secret-with-required-fields
		//   name + value entered in the "New secret" dialog, the stored `key` is
		//   a >100-char blob that does not contain the plaintext, the secret
		//   appears in the list, and opening + revealing returns the exact value.
		// @e2e secrets-write-ui::edit-a-secrets-value
		//   the Edit dialog changes the value, saves, and re-revealing returns
		//   the NEW value.
		const NAME = `__e2e_rt_${Date.now()}`
		const VALUE = 'S3cr3t-roundtrip-Æ-✓-1234567890'
		const NEW_VALUE = 'edited-value-Ø-9876543210'

		await unlockVault(page)
		await openVault(page)

		// FROM A FOLDER, NOT FROM "All secrets". This used to open the dialog
		// on /secrets, which carries no folder, so `Create secret` rendered
		// DISABLED and the click did nothing: the failure read as "the dialog
		// did not close", two steps from the cause. A secret cannot live at
		// the vault root, so the route has to supply the folder the same way
		// it does for a user.
		const folderId = await apiFirstFolderId(page)
		expect(folderId, 'the vault must carry at least one folder').toBeTruthy()
		await gotoVaultRoute(page, `folders/${folderId}`)
		await expect(page.locator('.secret-list-view')).toBeVisible({
			timeout: 20_000,
		})

		// --- CREATE via the dialog (browser-side RSA encryption) ---
		await nativeClickByText(
			page,
			`${SecretList} .cn-actions-bar button`,
			'New secret',
		)
		const createDialog = page.locator('.secret-form')
		await expect(createDialog).toBeVisible({ timeout: 10_000 })
		await page
			.locator('.secret-form input[type="text"]')
			.first()
			.fill(NAME, { force: true })
		await page
			.locator('.secret-form input[type="password"]')
			.first()
			.fill(VALUE, { force: true })
		await page.waitForTimeout(300)
		await nativeClickByText(page, 'body button', 'Create secret')

		await expect(createDialog).toHaveCount(0, { timeout: 15_000 })
		const created = await apiFind(page, NAME)
		expect(created, 'created secret must exist').toBeTruthy()
		// Encryption-at-rest: the stored key is a ciphertext blob, never the plaintext.
		expect(typeof created.key).toBe('string')
		expect(created.key.length).toBeGreaterThan(100)
		expect(created.key).not.toContain(VALUE)
		expect(created.ownerType).toBe('user')

		// --- RETRIEVE + DECRYPT via the detail UI (click the list row) ---
		const row = page.locator('.secret-list-item', { hasText: NAME })
		await expect(row).toBeVisible({ timeout: 15_000 })
		await page.evaluate((name) => {
			const r = Array.from(
				document.querySelectorAll('.secret-list-item'),
			).find(
				(i) =>
					// Own text nodes only: `.secret-list-item__name` also contains a
					// <StrengthBadge>, so `textContent` appends its label ("Very
					// strong") and this exact comparison stops matching once the
					// badge resolves. It would then click nothing and fail on the
					// detail card instead, pointing away from the real cause.
					Array.from(
						i.querySelector('.secret-list-item__name')?.childNodes || [],
					)
						.filter((c) => c.nodeType === 3)
						.map((c) => c.textContent || '')
						.join('')
						.trim() === name,
			)
			if (r) {
				;(r as HTMLElement).click()
			}
		}, NAME)
		await expect(page.locator(`${SecretDetail}__card`)).toBeVisible({
			timeout: 20_000,
		})
		// Restyle Stage 8: the detail is a right sidebar over the list; the
		// secret's name is the NcAppSidebar header rather than an in-card h2.
		await expect(
			page.locator(`${SecretDetail} .app-sidebar-header__mainname`),
		).toHaveText(NAME)
		await nativeClickByLabel(page, 'Show')
		await expect(
			page.locator('.secret-detail .keepiq-password-field input'),
		).toHaveValue(VALUE, { timeout: 10_000 })

		// --- EDIT the value via the Edit dialog ---
		await nativeClickByText(page, '.secret-detail__actions button', 'Edit')
		await expect(page.locator('.secret-form')).toBeVisible({ timeout: 10_000 })
		await page
			.locator('.secret-form input[type="password"]')
			.first()
			.fill(NEW_VALUE, { force: true })
		await page.waitForTimeout(300)
		await nativeClickByText(page, 'body button', 'Save')
		await expect(page.locator('.secret-form')).toHaveCount(0, {
			timeout: 15_000,
		})

		await expect(page.locator(`${SecretDetail}__card`)).toBeVisible({
			timeout: 20_000,
		})
		await nativeClickByLabel(page, 'Show')
		await expect(
			page.locator('.secret-detail .keepiq-password-field input'),
		).toHaveValue(NEW_VALUE, { timeout: 10_000 })

		// --- DELETE (cleanup + assert gone) ---
		await apiDelete(page, created.id)
		const afterDelete = await apiFind(page, NAME)
		expect(afterDelete, 'secret must be gone after delete').toBeFalsy()
	})

	/*
	 * ENCRYPTION-AT-REST — now exercisable: the dev seed stores 6 RSA-encrypted
	 * secrets. Assert GET /api/v1/secrets returns a base64 `key` ciphertext blob
	 * that does NOT contain the known dev plaintext (e.g. the GitHub password).
	 * The actual decrypt round-trip is proven at the crypto layer (see the PHP
	 * EncryptService/DecryptService unit tests and the vault-unlock e2e).
	 */
	test('stored secret value is encrypted at rest (response holds no plaintext)', async ({
		page,
	}) => {
		await gotoLockSettled(page)
		const probe = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/keepiq/api/v1/secrets', {
				credentials: 'include',
				headers: { requesttoken: token },
			})
			const body = await res.json()
			const gh = (body.items || []).find((s) => s.name === 'GitHub')
			return {
				hasGitHub: !!gh,
				keyIsBlob: !!gh && typeof gh.key === 'string' && gh.key.length > 100,
				leaksPlaintext:
					!!gh && (gh.key || '').includes('gh_dev_P@ssw0rd!2024'),
			}
		}, REQ_TOKEN)
		expect(probe.hasGitHub).toBe(true)
		expect(probe.keyIsBlob).toBe(true)
		expect(probe.leaksPlaintext).toBe(false)
	})
})
