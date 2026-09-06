/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Secret-type display helpers.
 *
 * The type records come from the server with ENGLISH labels (seeded by
 * lib/Repair/SeedSecretTypes.php — "Login", "Payment Card", …), so every
 * surface that printed `type.label` raw showed English regardless of the
 * user's language. Routing the label through the translator fixes that:
 * the system labels exist as keys in the l10n catalogs, and an unknown
 * (custom) label falls through `t()` unchanged — the same dynamic-key
 * pattern KeepiqAppNav uses for manifest menu labels.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The localized display label for a secret type.
 *
 * @param {object|null|undefined} type The type record ({ label, name }).
 * @return {string} The translated label, or '' without a type.
 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
 */
export function secretTypeLabel(type) {
	const label = type ? type.label || type.name : ''
	return label ? t('keepiq', label) : ''
}
