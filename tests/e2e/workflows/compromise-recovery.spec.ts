import type { Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Compromise recovery (key rotation + secret migration).
 *
 * Covers the four compromise-recovery scenarios that carry no `@e2e exclude`:
 * the rotate-every-value warning before the user confirms, live progress inside
 * the dialog, a terminal message that reports counts without claiming the vault
 * is secure, and the possibly-compromised warning on a migrated secret
 * afterwards.
 *
 * HOW THIS AVOIDS THE SEEDED-VAULT DEAD END
 *
 * workflows/vault-unlock.spec.ts documents (bug #5) that admin's seeded vault
 * cannot be unlocked by any password: `SeedDevelopmentData` writes the AES-GCM
 * private-key envelope with the PHP EncryptService and the browser's
 * decryptPrivateKey() rejects it. Recovery lives behind the unlock gate, so
 * driving it as admin is impossible.
 *
 * So this spec does not use admin. It logs in as a NON-ADMIN fixture user who
 * owns NO EncryptionSuite (alice, in the standard dev seed), which puts the
 * lock screen in FIRST-TIME SETUP mode. The vault is then created by the
 * BROWSER, so the envelope is JS-written and unlocks normally — and the whole
 * recovery flow becomes reachable end to end without touching the seeded
 * envelope at all.
 *
 * It also makes the test self-repairing: it creates the vault it goes on to
 * rotate, rather than rotating one that already held something. Rotation is
 * destructive to a vault's link shares and passkeys, so a spec that performs it
 * must own its subject. Point KEEPIQ_VAULT_USER at a throwaway account.
 *
 * On CI there is no dev seed, so `alice` does not exist and this spec used to
 * time out on /login?user=alice&direct=1 — a missing fixture that reads as a
 * broken login flow. tests/e2e/ci-seed.sh section 3 now provisions the account
 * (and forces its password) before Playwright starts.
 *
 * If the chosen user already owns a suite (a previous run, or a seed change),
 * setup mode will not appear; the spec detects that and skips rather than
 * silently asserting against the wrong surface.
 *
 * @e2e openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
 * @e2e openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
 * @e2e openspec/specs/encryption-suites/spec.md#requirement-suite-migration
 */
import { expect, test } from '@playwright/test'
import { APP_BASE, gotoVaultRoute } from './_workflow-helpers.ts'

/** A fixture account that owns no EncryptionSuite, so setup mode is reachable. */
const VAULT_USER = process.env.KEEPIQ_VAULT_USER ?? 'alice'
const VAULT_PASS = process.env.KEEPIQ_VAULT_PASS ?? 'alice'

/** Master passwords for the vault this spec creates and then rotates. */
const OLD_MASTER = 'Or1ginal-master-passphrase!'
const NEW_MASTER = 'R0tated-master-passphrase!'

/**
 * Start from a clean, unauthenticated context: the global setup stores an ADMIN
 * session, and this spec deliberately runs as somebody else.
 */
test.use({
	storageState: { cookies: [], origins: [] },
	// Wide enough that the app navigation is not collapsed. The settings entry
	// still gets a programmatic click below, because at 1280x720 Nextcloud keeps
	// the nav closed and the entry present-but-hidden.
	viewport: { width: 1600, height: 1000 },
})

/**
 * Log into Nextcloud as the vault fixture user.
 *
 * @param page The Playwright page.
 */
async function loginAsVaultUser(page: Page): Promise<void> {
	await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
	const userField = page.locator('input[name="user"]')
	await userField.waitFor({ state: 'visible', timeout: 40_000 })
	await userField.fill(VAULT_USER)
	await page.locator('input[name="password"]').fill(VAULT_PASS)
	await page.locator('form[name="login"] button[type="submit"]').click()
	await expect(page).not.toHaveURL(/\/login(\?|$|\/)/, { timeout: 40_000 })
}

/**
 * Click a button by its visible label, natively.
 *
 * The themed NcButton swallows Playwright's synthetic click, which is why every
 * existing workflow helper dispatches through page.evaluate.
 *
 * @param page  The Playwright page.
 * @param label A regex source matched case-insensitively against the label.
 */
async function clickByLabel(page: Page, label: string): Promise<void> {
	await page.evaluate((pattern) => {
		const re = new RegExp(pattern, 'i')
		const btn = Array.from(document.querySelectorAll('button')).find((b) =>
			re.test(b.textContent || ''),
		)
		if (btn) {
			;(btn as HTMLButtonElement).click()
		}
	}, label)
}

/**
 * Create the vault in the browser, so its envelope is JS-written.
 *
 * @param page The Playwright page (already logged in).
 * @return True when setup ran; false when the user already owned a suite.
 */
async function setUpVault(page: Page): Promise<boolean> {
	await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
	await page
		.locator('.lock-screen__card')
		.waitFor({ state: 'visible', timeout: 30_000 })

	// Setup mode has TWO password fields; unlock mode has one.
	const fields = page.locator('.lock-screen input[type="password"]')
	if ((await fields.count()) < 2) {
		return false
	}

	await fields.nth(0).fill(OLD_MASTER, { force: true })
	await fields.nth(1).fill(OLD_MASTER, { force: true })
	await page.waitForTimeout(400)
	await clickByLabel(page, 'Set up vault')
	await expect(page.locator('.lock-screen')).toHaveCount(0, { timeout: 30_000 })
	return true
}

/**
 * Create one secret holding REAL ciphertext, encrypted to the vault's own suite.
 *
 * The migration decrypts with the old private key before re-encrypting, so a
 * placeholder string is not merely unrealistic — it fails `rsaDecrypt` and the
 * record is correctly classified unrecoverable, which is a different test. To
 * exercise the happy path the blob has to be genuine.
 *
 * The chunked wire format is the one src/crypto/rsa.js writes:
 * [4-byte big-endian chunk count][512-byte RSA-OAEP blocks], 446 plaintext
 * bytes per chunk. The X.509 → SubjectPublicKeyInfo walk mirrors the app's
 * importPublicKey() and the same walk already used by
 * workflows/secret-crud-encryption.spec.ts.
 *
 * @param page  The Playwright page (vault unlocked).
 * @param name  The secret name.
 * @param value The plaintext to seal.
 */
async function seedEncryptedSecret(
	page: Page,
	name: string,
	value: string,
): Promise<void> {
	const created = await page.evaluate(
		async ([base, secretName, plaintext]) => {
			const token =
				(window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken || ''

			const suites = await (
				await fetch(`${base}/api/v1/suites`, {
					credentials: 'include',
					headers: { requesttoken: token, 'OCS-APIREQUEST': 'true' },
				})
			).json()
			const suite = suites.find(
				(x: { status: string }) => x.status === 'active',
			)
			if (!suite) {
				return { status: 0, body: 'no active suite' }
			}

			const certBody = String(suite.certificate)
				.replace(/-----BEGIN CERTIFICATE-----/, '')
				.replace(/-----END CERTIFICATE-----/, '')
				.replace(/\s/g, '')
			const der = Uint8Array.from(atob(certBody), (c) => c.charCodeAt(0))
			const readLen = (d: Uint8Array, o: number) => {
				const f = d[o]
				if ((f & 0x80) === 0) {
					return { length: f, headerEnd: o + 1 }
				}
				const n = f & 0x7f
				let l = 0
				for (let i = 0; i < n; i++) {
					l = (l << 8) | d[o + 1 + i]
				}
				return { length: l, headerEnd: o + 1 + n }
			}
			const outer = readLen(der, 1)
			const tbs = readLen(der, outer.headerEnd + 1)
			let pos = tbs.headerEnd
			const tbsEnd = tbs.headerEnd + tbs.length
			const fields: Array<{ tag: number; start: number; end: number }> = []
			while (pos < tbsEnd) {
				const tag = der[pos]
				const { length, headerEnd } = readLen(der, pos + 1)
				const end = headerEnd + length
				fields.push({ tag, start: pos, end })
				pos = end
			}
			const spkiIdx = fields[0].tag === 0xa0 ? 6 : 5
			const spki = der.slice(fields[spkiIdx].start, fields[spkiIdx].end)
			const pub = await crypto.subtle.importKey(
				'spki',
				spki,
				{ name: 'RSA-OAEP', hash: 'SHA-256' },
				false,
				['encrypt'],
			)

			const CHUNK = 446
			const BLOCK = 512
			const data = new TextEncoder().encode(plaintext)
			const chunks: Uint8Array[] = []
			for (let i = 0; i < data.length; i += CHUNK) {
				chunks.push(data.slice(i, i + CHUNK))
			}
			if (chunks.length === 0) {
				chunks.push(new Uint8Array(0))
			}
			const out = new Uint8Array(4 + chunks.length * BLOCK)
			new DataView(out.buffer).setUint32(0, chunks.length, false)
			for (let i = 0; i < chunks.length; i++) {
				const enc = new Uint8Array(
					await crypto.subtle.encrypt(
						{ name: 'RSA-OAEP' },
						pub,
						chunks[i],
					),
				)
				out.set(enc, 4 + i * BLOCK)
			}
			const ciphertext = btoa(String.fromCharCode(...out))

			const res = await fetch(`${base}/api/v1/secrets`, {
				method: 'POST',
				credentials: 'include',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: token,
					'OCS-APIREQUEST': 'true',
				},
				body: JSON.stringify({ name: secretName, key: ciphertext }),
			})
			return { status: res.status, body: (await res.text()).slice(0, 200) }
		},
		[APP_BASE, name, value] as const,
	)

	expect(created.status, `secret create failed: ${created.body}`).toBeLessThan(400)
}

/**
 * Open the user-settings dialog, then reveal the compromise-recovery form.
 *
 * The dialog is opened by CnAppRoot's own nav entry, matched on its stable
 * data-testid. Matching on the word "Settings" instead — as an earlier version
 * of this spec did — hits Nextcloud's account-menu Settings anchor, which is a
 * real navigation: it tears down the SPA, wipes the in-memory CryptoKey, and
 * fails with "Execution context was destroyed".
 *
 * @param page The Playwright page (vault unlocked).
 */
async function openRecoveryForm(page: Page): Promise<void> {
	// Present but hidden while the app navigation is collapsed, so wait for it in
	// the DOM and dispatch the click directly rather than requiring visibility —
	// the Vue handler fires either way, and this is the same native-click
	// technique _workflow-helpers.ts uses for themed controls.
	await page
		.locator('[data-testid="cn-nav-entry-UserSettings"]')
		.first()
		.waitFor({ state: 'attached', timeout: 30_000 })
	await page.evaluate(() => {
		const entry = document.querySelector(
			'[data-testid="cn-nav-entry-UserSettings"] a',
		)
		if (entry) {
			;(entry as HTMLElement).click()
		}
	})
	await page.waitForTimeout(1200)

	await clickByLabel(page, 'My master password was compromised')
	await expect(page.locator('.compromise-recovery-form')).toBeVisible({
		timeout: 15_000,
	})
}

test.describe('Workflow: compromise recovery — encryption-suites/spec.md', () => {
	/*
	 * The three encryption-suites scenarios that carry no `@e2e exclude` are all
	 * proved by this one test, because they are three assertions about the SAME
	 * dialog across one rotation: the warning before confirming, the live
	 * progress while it runs, and the terminal wording once it ends. Splitting
	 * them would mean rotating a vault three times to assert three sentences.
	 *
	 * @e2e encryption-suites::warning-shown-before-the-user-confirms
	 * @e2e encryption-suites::progress-is-visible-inside-the-recovery-dialog
	 * @e2e encryption-suites::terminal-message-is-not-an-all-clear
	 */
	test('a fresh vault rotates, and every surface reports the truth', async ({
		page,
	}) => {
		test.slow()

		await loginAsVaultUser(page)

		const didSetUp = await setUpVault(page)
		test.skip(
			didSetUp === false,
			`${VAULT_USER} already owns an EncryptionSuite, so first-time setup was not offered. `
				+ 'Reset that account or point KEEPIQ_VAULT_USER at one with no suite.',
		)

		// Several secrets so the run lasts long enough for progress to be
		// observable: RSA-4096 over each field is the slow part, which is the
		// whole reason the loop runs in a worker.
		for (let i = 0; i < 4; i++) {
			await seedEncryptedSecret(
				page,
				`e2e-rotation-${i}`,
				`rotation-subject-value-${i}`,
			)
		}

		await openRecoveryForm(page)
		const form = page.locator('.compromise-recovery-form')

		// SURFACE 1 — before confirm. Access restored, not safety.
		const before = await form.innerText()
		expect(before).toMatch(/must be assumed to have been exposed/i)
		expect(before).toMatch(/changed at its source/i)
		expect(before).toMatch(/restores access/i)
		expect(before).toMatch(/does not make the old values safe/i)

		// Drive the rotation.
		const pw = form.locator('input[type="password"]')
		await pw.nth(0).fill(OLD_MASTER, { force: true })
		await pw.nth(1).fill(NEW_MASTER, { force: true })
		await pw.nth(2).fill(NEW_MASTER, { force: true })
		await page.waitForTimeout(400)
		await clickByLabel(page, 'Start key rotation')

		// SURFACE 2 — during, and inside the dialog: the maintainer decision
		// recorded in design.md, not a toast and not a separate page.
		await expect(
			form.locator('.compromise-recovery-form__progress'),
		).toBeVisible({ timeout: 60_000 })

		// SURFACE 3 — terminal. Counts, and never an all-clear.
		await expect(form).toContainText(/Key rotation finished/i, {
			timeout: 180_000,
		})
		await expect(form).toContainText(/re-encrypted under your new key/i)
		await expect(form).toContainText(/still to be considered exposed/i)
		await expect(form).not.toContainText(
			/now secured with a new encryption key/i,
		)
		await expect(form).not.toContainText(/vault is now secure/i)

		// The migration must actually be terminal, not merely reported so — this
		// is the assertion the old premature-complete bug would have passed and
		// the gate could have failed.
		const status = await page.evaluate(async (base) => {
			const token =
				(window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken || ''
			const res = await fetch(`${base}/api/v1/migrations/status`, {
				headers: { requesttoken: token, 'OCS-APIREQUEST': 'true' },
			})
			return res.text()
		}, APP_BASE)
		expect(status).toContain('none')

		// SURFACE 4 — the migrated rows say so. This is what was entirely missing
		// before: six consumers of possibly_compromised_at and no producer, so no
		// row ever said anything.
		// The user-settings dialog is modal. Navigating the hash underneath it
		// leaves the vault list mounted but covered, so the row indicator is
		// never shown — close the dialogs first. They can be STACKED (the
		// recovery dialog sits on the user-settings dialog) and Escape closes
		// only the topmost, so press it once per remaining dialog instead of
		// once in total — the single-press form left one dialog standing and
		// was the recorded flake on CI.
		for (let i = 0; i < 5; i++) {
			if ((await page.locator('[role="dialog"]').count()) === 0) {
				break
			}
			await page.keyboard.press('Escape')
			await page.waitForTimeout(500)
		}
		await expect(page.locator('[role="dialog"]')).toHaveCount(0, {
			timeout: 15_000,
		})

		await gotoVaultRoute(page, 'secrets')
		await page.waitForTimeout(2500)

		const warned = page.locator('[data-testid="secret-possibly-compromised"]')
		await expect(warned.first()).toBeVisible({ timeout: 30_000 })
		await expect(warned.first()).toContainText(/change it at its source/i)
	})

	test('the resume banner stays silent when no migration is in progress', async ({
		page,
	}) => {
		// The negative case is the one that regresses quietly: a banner shown to
		// everyone gets noticed immediately, one shown to nobody does not.
		await loginAsVaultUser(page)
		await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
		await page
			.locator('.lock-screen__card')
			.waitFor({ state: 'visible', timeout: 30_000 })

		await expect(
			page.locator('[data-testid="migration-resume-banner"]'),
		).toHaveCount(0)
	})
})
