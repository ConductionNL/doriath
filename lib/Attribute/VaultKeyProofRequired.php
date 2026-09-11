<?php

/**
 * Keepiq Vault Key Proof Required attribute
 *
 * Marks a controller method as requiring a VaultKeyProof: a signature made with
 * the private key of the caller's EncryptionSuite over a server-issued
 * challenge, bound to the operation's own parameters. Because that private key
 * is obtainable only by decrypting its envelope with the master password, the
 * proof is a server-verifiable proof of the master password. Enforced by
 * VaultKeyProofMiddleware.
 *
 * The attribute carries the binding because the middleware cannot read the
 * request body: the framework decodes JSON and discards the raw bytes, so the
 * proof commits to NAMED request parameters instead, hashed individually in the
 * declared order. `subject` says whose public key verifies the signature.
 *
 * @category Attribute
 * @package  OCA\Keepiq\Attribute
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

namespace OCA\Keepiq\Attribute;

use Attribute;

/**
 * Require a verified vault-key proof on the annotated controller method.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class VaultKeyProofRequired {
	/**
	 * Constructor.
	 *
	 * @param string[] $binds  Request parameter names the proof commits to, in
	 *                         the order they are hashed into the signed payload.
	 *                         Empty means the proof binds to the challenge alone.
	 * @param string   $subject Whose public key verifies the proof:
	 *                         'active' (default) — the caller's active suite;
	 *                         'routeParam:<name>' — the suite named by that route
	 *                         parameter.
	 * @param string   $purpose A stable public identifier for this operation. A
	 *                         challenge is bound to one purpose, so a proof
	 *                         obtained for one guarded operation cannot be
	 *                         presented to another. The client requests its
	 *                         challenge with the same string.
	 *
	 * @return void
	 */
	public function __construct(
		private array $binds = [],
		private string $subject = 'active',
		private string $purpose = '',
	) {
	}//end __construct()

	/**
	 * The request parameter names the proof binds to, in payload order.
	 *
	 * @return string[]
	 */
	public function getBinds(): array {
		return $this->binds;
	}//end getBinds()

	/**
	 * How the subject suite is resolved.
	 *
	 * @return string
	 */
	public function getSubject(): string {
		return $this->subject;
	}//end getSubject()

	/**
	 * The stable purpose identifier this operation's challenge is bound to.
	 *
	 * @return string
	 */
	public function getPurpose(): string {
		return $this->purpose;
	}//end getPurpose()
}//end class
