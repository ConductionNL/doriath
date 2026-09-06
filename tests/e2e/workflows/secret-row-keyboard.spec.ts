/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Workflow — a secret row in the vault list is keyboard-operable (WCAG 2.1 AA
 * SC 2.1.1 Keyboard + 4.1.2 Name, Role, Value; ADR-010).
 *
 * GOAL: prove a keyboard-only user can Tab onto a secret row and open its
 * detail view with Enter (and that Space also activates it), with no mouse
 * events, and that a visible :focus-visible outline is rendered — matching the
 * mouse-click behaviour proven in secret-crud-encryption.spec.ts.
 *
 * Requires a live, seeded Keepiq instance (the dev seed ships secrets such as
 * "GitHub"). The vault is unlocked headlessly via the dev master password.
 *
 * ⚠️ THE FILE-LEVEL ANCHOR THAT USED TO BE HERE WAS DANGLING. It pointed at
 * `openspec/specs/secrets-write-ui/spec.md#secret-list-rows-must-be-keyboard-operable`
 * (the sigil is omitted on purpose: a literal tag written in prose is parsed by
 * gate-19 exactly like a real one, so quoting a broken anchor re-creates it)
 * and named a scenario that does not exist — the requirement HEADING is called that,
 * the scenarios under it are not. gate-19 reports nothing for a dangling anchor,
 * so these three passing tests were credited to zero scenarios while four real
 * ones sat in its findings list. Anchors are now per-test, against the real
 * slugs, so each claim is checkable against the body directly below it.
 */
import { expect, test } from '@playwright/test'
import { openVault, unlockVault } from './_workflow-helpers.ts'

test.describe('Workflow: secret rows are keyboard-operable — secrets-write-ui/spec.md', () => {
	test('Tab + Enter opens a secret from the list without a mouse', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::opening-a-secret-row-via-keyboard-only
		// @e2e secrets-write-ui::focused-row-shows-a-visible-focus-indicator
		await unlockVault(page)
		await openVault(page)

		const firstRow = page.locator('.secret-list-item').first()
		await expect(firstRow).toBeVisible({ timeout: 15_000 })

		// The row must expose an interactive role + accessible name.
		await expect(firstRow).toHaveAttribute('role', 'button')
		await expect(firstRow).toHaveAttribute('tabindex', '0')
		await expect(firstRow).toHaveAttribute('aria-label', /.+/)

		// Move keyboard focus onto the row (no mouse click) and activate it.
		await firstRow.focus()
		await expect(firstRow).toBeFocused()

		// A visible focus indicator must be rendered via :focus-visible, and the
		// scenario is specific about HOW: "using an NC CSS custom property, with
		// no hardcoded color value". A width check alone would pass on an
		// outline painted with a literal hex, so assert the source of the colour
		// as well as its presence.
		const outline = await firstRow.evaluate((el) => {
			const cs = getComputedStyle(el)
			// The declared (unresolved) value is what tells us whether a custom
			// property was used; getComputedStyle resolves var() away, so read
			// the authored rule out of the stylesheet that matched.
			let declared = ''
			for (const sheet of Array.from(document.styleSheets)) {
				let rules: CSSRuleList
				try {
					rules = sheet.cssRules
				} catch {
					continue // cross-origin sheet
				}
				for (const rule of Array.from(rules)) {
					const style = (rule as CSSStyleRule).style
					if (!style || !(rule as CSSStyleRule).selectorText) {
						continue
					}
					if (
						!/secret-list-item/.test((rule as CSSStyleRule).selectorText)
					) {
						continue
					}
					if (!/focus-visible/.test((rule as CSSStyleRule).selectorText)) {
						continue
					}
					const v =
						style.getPropertyValue('outline')
						|| style.getPropertyValue('outline-color')
					if (v) {
						declared += v + ';'
					}
				}
			}
			return { width: cs.outlineWidth, color: cs.outlineColor, declared }
		})
		expect(outline.width, 'no focus outline width').not.toBe('0px')
		expect(
			outline.declared,
			'no :focus-visible outline rule was found for .secret-list-item — '
				+ 'the focus indicator is not authored where the scenario says it is',
		).not.toBe('')
		expect(
			outline.declared,
			'the focus outline colour is hardcoded rather than an NC custom '
				+ `property (declared: ${outline.declared})`,
		).toMatch(/var\(\s*--/)

		await page.keyboard.press('Enter')

		// Enter on the focused row navigates to the secret's detail view.
		await expect(page.locator('.secret-detail__card')).toBeVisible({
			timeout: 20_000,
		})
	})

	test('Space also activates a focused row', async ({ page }) => {
		// @e2e secrets-write-ui::space-also-activates-a-focused-row
		await unlockVault(page)
		await openVault(page)

		const firstRow = page.locator('.secret-list-item').first()
		await expect(firstRow).toBeVisible({ timeout: 15_000 })

		await firstRow.focus()
		await expect(firstRow).toBeFocused()
		await page.keyboard.press('Space')

		await expect(page.locator('.secret-detail__card')).toBeVisible({
			timeout: 20_000,
		})
	})

	test('the copy-password control is independently focusable and does not open the row', async ({
		page,
		context,
	}) => {
		// @e2e secrets-write-ui::copy-control-does-not-trigger-row-navigation
		// The scenario has TWO clauses — "MUST copy the password and MUST NOT
		// also emit the row's open event". Only the second used to be asserted,
		// and an absence claim on its own is satisfied for free by a control
		// that does nothing at all. So the copy is asserted first: it is the
		// behavioural difference that proves the button was really activated.
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])
		await unlockVault(page)
		await openVault(page)

		// Find a row that renders the copy action (i.e. not a blocked secret).
		const copyButton = page.locator('.secret-list-item__actions button').first()
		await expect(copyButton).toBeVisible({ timeout: 15_000 })

		// The copy control is reachable via keyboard and, when activated, must
		// NOT navigate to the secret detail view (its keydown is stopped from
		// bubbling to the row's open handler).
		await copyButton.focus()
		await expect(copyButton).toBeFocused()
		await page.keyboard.press('Enter')

		// POSITIVE half: the activation actually did something. The value is
		// decrypted in the browser, so a non-empty clipboard is the observable.
		await expect
			.poll(
				async () =>
					await page.evaluate(() =>
						navigator.clipboard.readText().catch(() => ''),
					),
				{
					message:
						'the copy control was activated but the clipboard stayed '
						+ 'empty — the "does not navigate" assertion below would then '
						+ 'pass for a button that does nothing',
					timeout: 15_000,
				},
			)
			.not.toBe('')

		// NEGATIVE half: and it did NOT also open the row.
		await expect(page.locator('.secret-detail__card')).toHaveCount(0)
	})
})
