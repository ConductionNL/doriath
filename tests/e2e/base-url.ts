/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Single source of truth for the Nextcloud instance the e2e suite targets.
 *
 * WHY THIS EXISTS
 * ---------------
 * Specs used to compute the target themselves and fall back to
 * `http://localhost:8080` — the SHARED dev container. `keepiq-coverage.spec.ts`
 * did it with absolute literals in its WRITE paths: it created an OCS user,
 * drove a real LOGIN form and then deleted the user, so every local run fired
 * account provisioning and failed logins (brute-force throttling) into an
 * instance other people were working in. String-grepping for `localhost:8080`
 * is not enough on its own either — the same bug has been written with
 * structured `{ hostname: 'localhost', port: 8080 }` fields.
 *
 * Rules encoded here:
 *   - NO `?? 'http://localhost:8080'` fallback. An unset target is a hard
 *     error, not a silent redirect onto somebody else's instance.
 *   - CI's name is accepted. The shared quality workflow exports the target as
 *     `BASE_URL`, `NEXTCLOUD_URL` and `NC_BASE_URL` — not `PLAYWRIGHT_BASE_URL`.
 *     A resolver that accepts only the latter hard-fails every CI run (this is
 *     what happened to openconnector). All four names are therefore accepted;
 *     `NC_BASE_URL` was the one missing here.
 *   - One module, so absolute and relative navigation inside one spec cannot
 *     disagree about which instance they mean.
 */

import { assertInstancePermitted } from './shared-instance.ts'

/** Environment variable names accepted as the target, in priority order. */
const CANDIDATES = [
	'PLAYWRIGHT_BASE_URL',
	'BASE_URL',
	'NEXTCLOUD_URL',
	'NC_BASE_URL',
] as const

/**
 * Resolve the base URL of the Nextcloud under test.
 *
 * @throws When none of the accepted environment variables is set, or when the
 *         target is the shared development instance and no opt-in flag names
 *         it. See tests/e2e/shared-instance.ts.
 * @return The base URL, without a trailing slash.
 */
export function resolveBaseUrl(): string {
	for (const name of CANDIDATES) {
		const value = process.env[name]
		if (value && value.trim() !== '') {
			return assertInstancePermitted(value.trim().replace(/\/+$/, ''))
		}
	}

	throw new Error(
		'No Nextcloud base URL configured for the e2e suite. Set one of '
			+ CANDIDATES.join(', ')
			+ '. Refusing to default to http://localhost:8080 — that is the SHARED '
			+ 'dev container.',
	)
}

/** The resolved base URL, evaluated once per process. */
export const BASE_URL = resolveBaseUrl()
