/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Client helper for VaultKeyProof: fetch a challenge for a guarded operation,
 * sign it with the master password, and return the headers the guarded request
 * must carry. The four destructive flows use this rather than each re-fetching
 * and re-signing, and so that the header names live in exactly one place.
 *
 * The master password is used only to sign and is never sent; the proof headers
 * carry the challenge and the signature, nothing else. See the vault-key-proof
 * spec and src/crypto/reauth.js#proveMasterPassword.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { proveMasterPassword } from './reauth.js'

/** The header carrying the challenge the proof was made over. */
export const HEADER_NONCE = 'X-Keepiq-Key-Proof-Nonce'
/** The header carrying the base64 signature. */
export const HEADER_PROOF = 'X-Keepiq-Key-Proof'

/**
 * The stable purpose identifiers, matching VaultKeyProofService::PURPOSE_*.
 */
export const PROOF_PURPOSE = {
	COMPROMISE_RECOVERY: 'compromise-recovery',
	UPDATE_PRIVATE_KEY: 'update-private-key',
	COMPLETE_MIGRATION: 'complete-migration',
	EMERGENCY_DESTROY: 'emergency-access-destroy',
}

/**
 * Build the proof headers for a guarded request.
 *
 * @param {object} params The proof parameters.
 * @param {string} params.suiteId The suite the proof is made with (for the challenge URL).
 * @param {string} params.purpose One of PROOF_PURPOSE.
 * @param {string} params.encryptedPrivateKey The subject suite's stored AES envelope.
 * @param {string} params.masterPassword The freshly entered master password.
 * @param {string[]} [params.boundValues] The request parameters the proof commits to, in order.
 * @return {Promise<Record<string,string>>} Headers to merge into the guarded request.
 */
export async function buildKeyProofHeaders({
	suiteId,
	purpose,
	encryptedPrivateKey,
	masterPassword,
	boundValues = [],
}) {
	const { data } = await axios.get(
		generateUrl(`/apps/keepiq/api/v1/suites/${suiteId}/proof-challenge`),
		{ params: { purpose } },
	)

	const signature = await proveMasterPassword(
		encryptedPrivateKey,
		masterPassword,
		data.nonce,
		boundValues,
	)

	return {
		[HEADER_NONCE]: data.nonce,
		[HEADER_PROOF]: signature,
	}
}
