/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. So whichever config it picks, EVERY
 * project in it runs. The root `playwright.config.ts` declares two:
 *
 *   chromium — the spec-coverage + workflow regression suite. CI wants this.
 *   visual   — pixel-diff baselines. Its own header states the PNGs are
 *              host-font/GPU specific and "a CI Linux runner will not
 *              byte-match a dev-container baseline", so it cannot pass here.
 *
 * Letting the root config be picked therefore runs a project that is
 * documented as unable to pass on a CI runner. Rather than delete or weaken
 * it, `playwright-test-path: tests/e2e` in the caller makes the workflow's
 * FIRST lookup hit this file, which declares only the regression project. The
 * root config is untouched and stays the entry point for local runs and
 * `--project visual`.
 *
 * The report/output paths also differ deliberately: the workflow uploads
 * `server/apps/<app>/playwright-report/` and `server/apps/<app>/test-results/`,
 * so on CI the artifacts must land at the APP ROOT, not under `tests/e2e/`.
 * With the root config's paths the "Upload Playwright report" step matches
 * nothing and silently uploads an empty artifact (`if-no-files-found: ignore`)
 * — a failing run with no report to read.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'

const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. Measured
	// overhead before `Run Playwright tests` starts is 2.0-2.4 min and the
	// uploads after it take seconds, so 38m keeps ~7 min of margin while
	// guaranteeing both a tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: [
		[
			'html',
			{
				open: 'never',
				outputFolder: path.join(APP_ROOT, 'playwright-report'),
			},
		],
		['list'],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		baseURL: BASE_URL,
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		// `on-first-retry` writes a trace only when a retry actually happens, so
		// the trace artifact is a function of `retries`. Off CI `retries` is 0
		// above, so a local failure has never produced a trace at all; on CI it
		// traces the SECOND attempt only, which means the failure that does not
		// reproduce — the one actually worth a trace — leaves no record of the
		// attempt that failed. `retain-on-failure` traces every attempt and
		// keeps the ones that failed: strictly more informative, and
		// independent of the retry count.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			// Mirrors the root config's chromium project ignore list. A
			// project-level `testIgnore` REPLACES the top-level one, so every
			// entry the root carries has to be repeated here rather than
			// inherited — `**/visual/**` in particular, because the visual
			// project's committed PNG baselines cannot byte-match a CI runner.
			testIgnore: [
				'**/node_modules/**',
				'**/custom_apps/**',
				'**/.claude/**',
				'**/visual/**',
			],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
