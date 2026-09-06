import { expect, test } from '@playwright/test'
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage — Keepiq admin settings page.
 *
 * The admin settings page is a Nextcloud settings section (not gated by the
 * vault lock), so it is fully reachable and renders the Keepiq Vue admin UI:
 * a CnVersionInfoCard, a Password Policy section, and a Certificate Authority
 * health section. These are real, data-independent surfaces.
 *
 * ⚠️ ANCHORS ARE PER-TEST, NOT FILE-LEVEL. A file-level `@e2e` tag is credited by
 * gate-19 WITHOUT the gate looking at any test body (.github#343), so a file
 * that carries four of them is claiming four scenarios on the strength of its
 * header. Three of the four that used to live here —
 * `#version-info-card`, `#password-policy-section`, `#ca-health-section` — named
 * scenarios that DO NOT EXIST in `openspec/specs/admin-settings/spec.md`; they
 * were dangling, gate-19 says nothing about a dangling anchor, and the tests
 * below were credited to nothing while passing on every run. The real scenario
 * slugs are `admin-opens-settings` and `ca-healthy`.
 */
import * as fs from 'fs'
import * as path from 'path'
import { assertNoKeepiqErrors, collectKeepiqErrors } from './_helpers.ts'

const ADMIN_SETTINGS = '/index.php/settings/admin/keepiq'

// Read the app version from appinfo/info.xml so the version-card assertion
// tracks the real deployed version instead of a hard-coded literal that breaks
// on every cache-bust bump.
const APP_VERSION = (() => {
	const infoXml = fs.readFileSync(
		path.resolve(__dirname, '../../../appinfo/info.xml'),
		'utf8',
	)
	return infoXml.match(/<version>([^<]+)<\/version>/)?.[1] ?? ''
})()

test.describe('Admin settings — spec: admin-settings/spec.md', () => {
	test('settings section loads with the Keepiq administration heading', async ({
		page,
	}) => {
		const errors = collectKeepiqErrors(page)
		await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded' })

		const heading = page.locator('#app-content h1, #content h1').first()
		await expect(heading).toBeVisible({ timeout: 15_000 })
		await expect(heading).toHaveText(/Keepiq/i)

		assertNoKeepiqErrors(errors)
	})

	test('Version Information card shows app name and the current app version', async ({
		page,
	}) => {
		// @e2e admin-settings::admin-opens-settings
		// The scenario is "the FIRST section MUST be a CnVersionInfoCard with app
		// name Keepiq and the current version" — which is what this asserts.
		const errors = collectKeepiqErrors(page)
		await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded' })

		const content = page.locator('#app-content, #content').first()
		await expect(content.getByText(/Version Information/i).first()).toBeVisible({
			timeout: 15_000,
		})
		// CnVersionInfoCard renders the app name and the current version
		// (read from appinfo/info.xml so this tracks cache-bust bumps).
		await expect(content.getByText(/Application Name/i).first()).toBeVisible()
		await expect(
			content.getByText(APP_VERSION, { exact: false }).first(),
		).toBeVisible()
		// Support footer slot is rendered by AdminRoot.vue.
		await expect(
			content.getByText(/support@conduction\.nl/i).first(),
		).toBeVisible()

		assertNoKeepiqErrors(errors)
	})

	test('Password Policy section renders', async ({ page }) => {
		const errors = collectKeepiqErrors(page)
		await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded' })

		const content = page.locator('#app-content, #content').first()
		await expect(content.getByText(/Password Policy/i).first()).toBeVisible({
			timeout: 15_000,
		})

		assertNoKeepiqErrors(errors)
	})

	test('Certificate Authority section renders and reports Healthy status', async ({
		page,
	}) => {
		// @e2e admin-settings::ca-healthy
		// Scenario: GIVEN the CA is bootstrapped and no renewal is needed, WHEN
		// admin views settings, THEN the CA section MUST show "Healthy" status
		// WITH ROOT AND INTERMEDIATE EXPIRY DATES. All three clauses are asserted
		// below — the expiry dates were previously not checked at all.
		const errors = collectKeepiqErrors(page)

		// The scenario's GIVEN, as a HARD precondition.
		//
		// ⚠️ THIS PROBE WAS BROKEN AND THE TEST WAS GREEN BECAUSE OF IT. It used
		// to read
		//
		//     const caHealthy = apiRes.ok() && (await apiRes.json()).status === 'healthy'
		//     …
		//     if (caHealthy) { await expect(…/Healthy/…).toBeVisible() }
		//
		// with the request sent through `page.request` carrying only Basic auth
		// and `X-Requested-With`. Nextcloud answers that **412 Precondition
		// Failed** (the CSRF guard — `page.request` is a separate context and
		// carries no `requesttoken`). `.ok()` was therefore false on EVERY run,
		// `caHealthy` was false, and the one assertion the test exists for was
		// skipped every single time while the test reported green. Measured here
		// by making the status a hard expectation: it failed instantly with
		// `Expected: 200 / Received: 412`.
		//
		// The fix is to ask from inside the page, where the session cookie and
		// the request token both travel — the same way the app itself asks.
		await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded' })
		const caStatus = await page.evaluate(async () => {
			const head = document.querySelector('head[data-requesttoken]')
			const token =
				(head && head.getAttribute('data-requesttoken'))
				|| ((window as any).OC && (window as any).OC.requestToken)
				|| ''
			const res = await fetch('/index.php/apps/keepiq/api/v1/ca/status', {
				credentials: 'include',
				headers: { requesttoken: token, Accept: 'application/json' },
			})
			return { status: res.status, body: await res.json().catch(() => ({})) }
		})
		expect(caStatus.status, 'GET /api/v1/ca/status').toBe(200)
		expect(
			caStatus.body.status,
			"the CA is not bootstrapped, so the scenario's GIVEN does not hold — "
				+ 'fix the fixture rather than skipping the assertion',
		).toBe('healthy')

		const content = page.locator('#app-content, #content').first()
		await expect(
			content.getByText(/Certificate Authority/i).first(),
		).toBeVisible({ timeout: 15_000 })
		await expect(content.getByText(/Healthy|Gezond/i).first()).toBeVisible({
			timeout: 15_000,
		})

		// …WITH root and intermediate expiry dates. The section must name both
		// tiers and render a date for each; a section that shows only the word
		// "Healthy" does not satisfy the scenario.
		await expect(content.getByText(/\broot\b/i).first()).toBeVisible({
			timeout: 15_000,
		})
		await expect(content.getByText(/intermediate/i).first()).toBeVisible()
		const sectionText = (await content.textContent()) ?? ''
		const dates = sectionText.match(/\d{1,4}[-/]\d{1,2}[-/]\d{1,4}/g) ?? []
		expect(
			dates.length,
			`the CA section rendered no expiry dates (text was: ${sectionText.slice(0, 400)})`,
		).toBeGreaterThanOrEqual(2)

		assertNoKeepiqErrors(errors)
	})
})
