<?php

/**
 * Keepiq Key Proof Required Exception
 *
 * Thrown when a guarded operation is requested without a valid VaultKeyProof —
 * a missing, malformed, expired, mis-purposed, mis-bound, or unverifiable
 * signature over the server-issued challenge. Every failure path throws this
 * one type with no detail leaked about which check failed, and the middleware
 * maps it to an HTTP 403 carrying the machine-readable code `key_proof_required`
 * so the client knows to obtain a challenge and retry rather than giving up.
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Exception;

use RuntimeException;

/**
 * Thrown when a required vault-key proof is absent or does not verify.
 */
class KeyProofRequiredException extends RuntimeException {
}//end class
