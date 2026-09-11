<?php

/**
 * Keepiq Vault Key Proof Service
 *
 * Issues and verifies VaultKeyProofs: a proof of master-password knowledge
 * expressed as a signature, made with the caller's EncryptionSuite private key,
 * over a server-issued challenge bound to the operation's parameters.
 *
 * The challenge is STATELESS. It carries a random component and is authenticated
 * with the instance secret over that component, the caller, the purpose, and an
 * expiry — so it can be verified without any server-side store. This is
 * deliberate: Nextcloud returns a null cache when none is configured, and a
 * nonce store that silently forgets would make every guarded flow unusable on a
 * default install. Single-use enforcement is unnecessary because the signature
 * commits to the operation's parameters, so a replay only ever re-authorises the
 * byte-identical operation.
 *
 * The proof is a SIGNATURE, never a decryption. The browser's session key is
 * non-extractable and decrypt-only, so a decrypt challenge would be satisfiable
 * by an unlocked tab (and thus by injected script); signing needs the raw
 * private key, which needs the master password. See the vault-key-proof spec.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Exception\KeyProofRequiredException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;

/**
 * Stateless issuance and verification of vault-key proofs.
 */
class VaultKeyProofService {
	/**
	 * How long a challenge is valid, in seconds.
	 */
	private const TTL = 300;

	/**
	 * Stable public purpose identifiers. Both the guarded method's attribute and
	 * the client's challenge request name one of these, and the challenge is
	 * bound to it, so a proof for one operation cannot be presented to another.
	 */
	public const PURPOSE_COMPROMISE_RECOVERY = 'compromise-recovery';
	public const PURPOSE_UPDATE_PRIVATE_KEY = 'update-private-key';
	public const PURPOSE_COMPLETE_MIGRATION = 'complete-migration';
	public const PURPOSE_EMERGENCY_DESTROY = 'emergency-access-destroy';

	/**
	 * The purposes a challenge may be issued for.
	 */
	public const ALLOWED_PURPOSES = [
		self::PURPOSE_COMPROMISE_RECOVERY,
		self::PURPOSE_UPDATE_PRIVATE_KEY,
		self::PURPOSE_COMPLETE_MIGRATION,
		self::PURPOSE_EMERGENCY_DESTROY,
	];

	/**
	 * Constructor.
	 *
	 * @param IConfig $config The system config, for the instance secret
	 * @param ISecureRandom $secureRandom The challenge randomness source
	 * @param ITimeFactory $timeFactory The clock, injected for testable expiry
	 *
	 * @return void
	 */
	public function __construct(
		private IConfig $config,
		private ISecureRandom $secureRandom,
		private ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Issue a challenge for a caller and a purpose.
	 *
	 * @param string $userId The caller's user id
	 * @param string $purpose The operation the challenge authorises
	 *
	 * @return array{nonce:string,expiresAt:int}
	 */
	public function issueChallenge(string $userId, string $purpose): array {
		$expiresAt = ($this->timeFactory->getTime() + self::TTL);

		$payload = $this->b64url(raw: (string)json_encode([
			'r' => base64_encode($this->secureRandom->generate(18)),
			'u' => $userId,
			'p' => $purpose,
			'e' => $expiresAt,
		]));

		$nonce = $payload . '.' . $this->mac(payload: $payload);

		return ['nonce' => $nonce, 'expiresAt' => $expiresAt];
	}//end issueChallenge()

	/**
	 * Verify a proof, or throw.
	 *
	 * Every failure path throws the same KeyProofRequiredException with no
	 * indication of which check failed, so a caller learns only pass/fail.
	 *
	 * @param string $nonce The challenge the client echoed back
	 * @param string $signatureB64 The base64 signature over the bound payload
	 * @param string $certificatePem The subject suite's certificate (its public key)
	 * @param string $userId The caller, which the challenge must name
	 * @param string $purpose The operation, which the challenge must name
	 * @param string[] $boundValues The request parameter values the proof commits to
	 *
	 * @return void
	 *
	 * @throws KeyProofRequiredException When the proof is absent, stale, mis-bound or invalid
	 */
	public function verify(
		string $nonce,
		string $signatureB64,
		string $certificatePem,
		string $userId,
		string $purpose,
		array $boundValues,
	): void {
		$claims = $this->authenticateNonce(nonce: $nonce);

		if (($claims['u'] ?? null) !== $userId || ($claims['p'] ?? null) !== $purpose) {
			throw new KeyProofRequiredException(message: 'Challenge does not match this operation');
		}

		if ((int)($claims['e'] ?? 0) < $this->timeFactory->getTime()) {
			throw new KeyProofRequiredException(message: 'Challenge has expired');
		}

		$publicKey = openssl_pkey_get_public($certificatePem);
		if ($publicKey === false) {
			throw new KeyProofRequiredException(message: 'Subject public key unreadable');
		}

		$signature = base64_decode($signatureB64, true);
		if ($signature === false) {
			throw new KeyProofRequiredException(message: 'Malformed proof');
		}

		$verified = openssl_verify(
			$this->signedMessage(nonce: $nonce, boundValues: $boundValues),
			$signature,
			$publicKey,
			OPENSSL_ALGO_SHA256
		);

		if ($verified !== 1) {
			throw new KeyProofRequiredException(message: 'Proof does not verify');
		}
	}//end verify()

	/**
	 * The exact string a valid proof signs: the challenge, then the SHA-256 of
	 * each bound value in declared order, one per line. The client builds the
	 * identical string, so only named scalar parameters cross the language
	 * boundary — no JSON-canonicalisation agreement is needed.
	 *
	 * @param string $nonce The challenge
	 * @param string[] $boundValues The bound request-parameter values, in order
	 *
	 * @return string
	 */
	public function signedMessage(string $nonce, array $boundValues): string {
		$lines = [$nonce];
		foreach ($boundValues as $value) {
			$lines[] = hash('sha256', (string)$value);
		}

		return implode("\n", $lines);
	}//end signedMessage()

	/**
	 * Recover and authenticate a challenge's claims, or throw.
	 *
	 * @param string $nonce The challenge string
	 *
	 * @return array<string,mixed>
	 *
	 * @throws KeyProofRequiredException When the challenge is absent or forged
	 */
	private function authenticateNonce(string $nonce): array {
		if ($nonce === '') {
			throw new KeyProofRequiredException(message: 'No challenge presented');
		}

		$parts = explode('.', $nonce);
		if (count($parts) !== 2) {
			throw new KeyProofRequiredException(message: 'Malformed challenge');
		}

		[$payload, $mac] = $parts;
		if (hash_equals($this->mac(payload: $payload), $mac) === false) {
			throw new KeyProofRequiredException(message: 'Challenge failed authentication');
		}

		$json = base64_decode(strtr($payload, '-_', '+/'), true);
		if ($json === false) {
			throw new KeyProofRequiredException(message: 'Unreadable challenge');
		}

		$claims = json_decode($json, true);
		if (is_array($claims) === false) {
			throw new KeyProofRequiredException(message: 'Unreadable challenge');
		}

		return $claims;
	}//end authenticateNonce()

	/**
	 * The HMAC of a payload under the instance secret, base64url-encoded.
	 *
	 * @param string $payload The base64url payload
	 *
	 * @return string
	 */
	private function mac(string $payload): string {
		$secret = $this->config->getSystemValueString('secret', '');
		return $this->b64url(raw: hash_hmac('sha256', $payload, $secret, true));
	}//end mac()

	/**
	 * URL-safe, unpadded base64.
	 *
	 * @param string $raw The raw bytes
	 *
	 * @return string
	 */
	private function b64url(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}//end b64url()
}//end class
