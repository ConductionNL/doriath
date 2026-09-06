/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for `@nextcloud/axios`.
 *
 * The real `@nextcloud/axios` wraps `axios` with CSRF + auth headers
 * sourced from the Nextcloud window globals. Tests don't need that
 * wiring — they replace `default.get/post/delete/...` with `vi.fn()`
 * via `vi.spyOn(axios, '...')` after importing.
 *
 * The stub is a plain object whose methods reject by default (so an
 * unstubbed call in a test fails loudly with a useful message rather
 * than going out to a real network).
 */

function notStubbed(method) {
	return () =>
		Promise.reject(
			new Error(
				`[test stub] @nextcloud/axios.${method} called without a vi.spyOn() override`,
			),
		)
}

const axios = {
	get: notStubbed('get'),
	post: notStubbed('post'),
	put: notStubbed('put'),
	patch: notStubbed('patch'),
	delete: notStubbed('delete'),
	request: notStubbed('request'),
}

export default axios
export const { get, post, put, patch, delete: del, request } = axios
