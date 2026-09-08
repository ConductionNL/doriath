/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The single place the public fill-link URL is built.
 *
 * It lives here because getting it wrong is not a cosmetic bug. Several forms
 * exist and only one works for the person the link is for:
 *
 *   /apps/keepiq/public/share/request/<token>   200 — the anonymous SPA shell
 *   /apps/keepiq/public#/share/request/<token>  dead — the RETIRED hash form:
 *                                               served, but only resolved by the
 *                                               bootstrap handoff kept for links
 *                                               already sent
 *   /apps/keepiq/share/request/<token>          401 — requires a Nextcloud account
 *   /api/v1/public/secret-requests/<token>       200 — JSON, for machines
 *
 * The dialog shipped the fourth form once and the third form before that, so a
 * requester handed a recipient either raw JSON or a login wall; the hash form
 * shipped after the router moved to createWebHistory, which reads no fragment,
 * so it showed recipients the lock screen. With the URL built in one function,
 * a second consumer cannot reintroduce a variant: the list and the dialog now
 * produce the same string by construction.
 */

import { generateUrl } from '@nextcloud/router'

/**
 * Build the absolute, human-openable fill link for a request token.
 *
 * The recipient has no Nextcloud account, so this must be the anonymous shell
 * (`publicShell#pageCatchAll`) carrying the route as a PATH — never the
 * authenticated path, never the JSON endpoint, and never the retired hash form.
 *
 * ABSOLUTE, scheme and host included. A link a requester pastes into a mail or
 * a chat has to work from outside the browser that produced it, and the machine
 * API already hands out the full URL for the same token
 * (ApplicationSecretRequestsController wraps it in `getAbsoluteURL`), so a bare
 * path here made the same request look like two different links.
 *
 * It used to pass `{ absolute: true }` to `generateUrl`, which reads like it
 * says so — but @nextcloud/router's `generateUrl` has no such option and
 * silently ignored it, leaving a root-relative path. The origin is prepended
 * here instead; `generateUrl` has already applied the instance's root path, so
 * resolving against the origin (not `getBaseUrl()`) keeps a subfolder install
 * from getting that prefix twice.
 *
 * @param {string} token The request's fill token.
 *
 * @return {string} The absolute URL, or '' when there is no token.
 *
 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-link-recovery
 */
export function fillLinkFor(token) {
	if (!token) {
		return ''
	}

	const path =
		generateUrl('/apps/keepiq/public')
		+ `/share/request/${encodeURIComponent(token)}`

	return new URL(path, window.location.origin).href
}
