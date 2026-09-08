<?php

/**
 * Keepiq JWT Assertion Verifier
 *
 * The JOSE half of the JWT-Bearer exchange: deserialising a
 * Compact-Serialized JWS, decoding and vetting its claim set (audience,
 * expiry, issued-at, bounded lifetime), and verifying its signature
 * against a supplied JWK. It knows nothing about applications, suites,
 * caches or audit trails — JwtAuthService owns those — which keeps every
 * jose-framework type behind this one seam.
 *
 * Algorithm priority is RS256 (primary, mandatory for production); ES256
 * is supported as a fallback when the issuer's certificate carries an EC
 * public key.
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

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWS;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Deserializes, vets and signature-verifies a JWT bearer assertion.
 */
class JwtAssertionVerifier {
	/**
	 * Constructor for JwtAssertionVerifier.
	 *
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Deserialize an assertion and return its claim set, having asserted
	 * that every required claim is present and acceptable.
	 *
	 * Required claims: iss (application id), aud (PRESENCE only — which values
	 * name this instance is AudiencePolicy's decision, asserted by
	 * JwtAuthService), exp (>now), iat (<=now+CLOCK_SKEW), jti.
	 *
	 * @param string $assertion The JWS compact serialization
	 *
	 * @return array<string,mixed> The decoded claim set
	 *
	 * @throws RuntimeException When the assertion is malformed or a claim
	 *                          is missing or unacceptable.
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
	 */
	public function readAcceptableClaims(string $assertion): array {
		$claims = $this->readAssertionClaims(jws: $this->deserializeAssertion(assertion: $assertion));
		$this->assertClaimsAcceptable(claims: $claims);

		return $claims;
	}//end readAcceptableClaims()

	/**
	 * Verify an assertion's signature against the issuer's public key.
	 *
	 * @param string $assertion The JWS compact serialization
	 * @param JWK $jwk The issuer's verification key
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the signature does not verify.
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
	 */
	public function verifySignature(string $assertion, JWK $jwk): void {
		$jws = $this->deserializeAssertion(assertion: $assertion);

		// RS256 primary, ES256 fallback.
		$algorithmManager = new AlgorithmManager([new RS256(), new ES256()]);
		$verifier = new JWSVerifier($algorithmManager);

		if ($verifier->verifyWithKey($jws, $jwk, 0) === false) {
			throw new RuntimeException(message: 'Assertion signature verification failed');
		}
	}//end verifySignature()

	/**
	 * Deserialize a Compact-Serialized JWS, or reject it as malformed.
	 *
	 * @param string $assertion The JWS compact serialization
	 *
	 * @return JWS
	 *
	 * @throws RuntimeException When the assertion cannot be deserialized.
	 */
	private function deserializeAssertion(string $assertion): JWS {
		$serializerManager = new JWSSerializerManager([new CompactSerializer()]);
		try {
			return $serializerManager->unserialize($assertion);
		} catch (Throwable $e) {
			$this->logger->warning(
				'JwtAuthService: failed to deserialize assertion (' . $e->getMessage() . ')',
				['app' => 'keepiq']
			);
			throw new RuntimeException(message: 'Invalid assertion format');
		}
	}//end deserializeAssertion()

	/**
	 * Decode the assertion payload and assert every required claim is present.
	 *
	 * @param JWS $jws The deserialized assertion
	 *
	 * @return array<string,mixed> The decoded claim set
	 *
	 * @throws RuntimeException When the payload is absent, not a JSON object,
	 *                          or a required claim is missing.
	 */
	private function readAssertionClaims(JWS $jws): array {
		$payloadRaw = $jws->getPayload();
		if ($payloadRaw === null) {
			throw new RuntimeException(message: 'Assertion has no payload');
		}

		// Decoded WITHOUT assoc, then cast at the top level only. `json_decode`
		// with $associative=true erases the difference between a JSON array and
		// a JSON object, so `"aud": {"target": "keepiq"}` would arrive as a PHP
		// array indistinguishable from `["keepiq"]` and match on its values.
		// array_is_list() does not recover it either: `{"0": "keepiq"}` decodes
		// to a list. Keeping nested objects as stdClass is what lets a claim
		// check reject one.
		$decoded = json_decode($payloadRaw);
		if (is_object($decoded) === false) {
			throw new RuntimeException(message: 'Assertion payload is not a JSON object');
		}

		$claims = (array)$decoded;

		// Required claims.
		foreach (['iss', 'aud', 'exp', 'iat', 'jti'] as $required) {
			if (array_key_exists($required, $claims) === false) {
				throw new RuntimeException(message: 'Missing required claim: ' . $required);
			}
		}

		return $claims;
	}//end readAssertionClaims()

	/**
	 * Audience, expiry, issued-at and lifetime checks on a decoded claim set.
	 *
	 * @param array<string,mixed> $claims The decoded claim set
	 *
	 * @return void
	 *
	 * @throws RuntimeException When any claim is unacceptable.
	 */
	private function assertClaimsAcceptable(array $claims): void {
		$now = time();

		// The audience is asserted by AudiencePolicy, from JwtAuthService: which
		// values this deployment answers to is a published contract decision,
		// not a property of a well-formed JWS.

		if ((int)$claims['exp'] <= $now) {
			throw new RuntimeException(message: 'Assertion expired');
		}

		if ((int)$claims['iat'] > ($now + JwtAuthService::CLOCK_SKEW_SECONDS)) {
			throw new RuntimeException(message: 'Assertion iat in future');
		}

		// Bound the assertion lifetime to the documented maximum so a
		// consumer cannot mint a long-lived signed assertion that would
		// sit replayable in the jti window for hours (secret-store-api D7).
		if (((int)$claims['exp'] - (int)$claims['iat']) > JwtAuthService::ACCESS_TOKEN_TTL) {
			throw new RuntimeException(
				message: 'Assertion lifetime exceeds the maximum of '
				. JwtAuthService::ACCESS_TOKEN_TTL . ' seconds'
			);
		}
	}//end assertClaimsAcceptable()
}//end class
