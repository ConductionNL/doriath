<?php

/**
 * Keepiq JWT Authentication Service
 *
 * Exchanges a JWT bearer assertion (RS256, signed by an application's
 * registered private key) for a short-lived opaque access token usable
 * on `/api/v1/app/*` routes. Verification uses the application's
 * EncryptionSuite certificate public key. Replay protection is enforced
 * via a distributed jti cache.
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

use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Implements the JWT-Bearer "assertion -> access_token" exchange that
 * powers the `/api/v1/token` endpoint plus the access-token validation
 * helper consumed by JwtAuthMiddleware.
 *
 * The JOSE work (deserialising, claim vetting, signature verification)
 * lives in JwtAssertionVerifier and the issuer's verification key comes
 * from ApplicationJwkResolver; what remains here is the exchange policy
 * itself: issuer resolution, jti replay protection, opaque access-token
 * minting and validation.
 */
class JwtAuthService {
	/**
	 * Distributed cache namespace for jti replay protection.
	 *
	 * KEPT ON THE OLD `keepiq_` PREFIX ACROSS THE RENAME, deliberately.
	 * The jti cache IS the replay-protection window: renaming the namespace
	 * empties it, and every assertion already spent during the preceding
	 * CLOCK_SKEW+exp window becomes replayable exactly once more. Keeping
	 * the namespace keeps the window intact across the upgrade. The cost of
	 * keeping it is zero — no client ever sees this string.
	 *
	 * @var string
	 */
	public const JTI_CACHE_NS = 'keepiq_jwt_jti';

	/**
	 * Distributed cache namespace for opaque access tokens.
	 *
	 * Kept on the old prefix for the same reason as JTI_CACHE_NS: renaming
	 * it invalidates every live bearer token mid-flight, 401-ing machine
	 * consumers that hold a token issued seconds before the upgrade.
	 *
	 * @var string
	 */
	public const TOKEN_CACHE_NS = 'keepiq_jwt_token';

	/**
	 * Lifetime of issued access tokens in seconds (5 minutes per spec
	 * §4.3).
	 *
	 * @var int
	 */
	public const ACCESS_TOKEN_TTL = 300;

	/**
	 * Allowed clock skew between issuer (`iat`) and verifier in seconds.
	 *
	 * @var int
	 */
	public const CLOCK_SKEW_SECONDS = 60;


	/**
	 * Constructor for JwtAuthService.
	 *
	 * @param ApplicationMapper $applicationMapper The application mapper
	 * @param ICacheFactory $cacheFactory The cache factory
	 * @param JwtAssertionVerifier $verifier The JOSE assertion verifier
	 * @param ApplicationJwkResolver $keyResolver The issuer key resolver
	 * @param AudiencePolicy $audiencePolicy Which `aud` values name this instance
	 * @param IEventDispatcher|null $eventDispatcher The event dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 */
	public function __construct(
		private ApplicationMapper $applicationMapper,
		private ICacheFactory $cacheFactory,
		private JwtAssertionVerifier $verifier,
		private ApplicationJwkResolver $keyResolver,
		private AudiencePolicy $audiencePolicy = new AudiencePolicy(new NullLogger()),
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Dispatch a typed audit event, fail-soft.
	 *
	 * @param AuditEvent $event The audit event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
	 */
	private function dispatchAudit(AuditEvent $event): void {
		$this->eventDispatcher?->dispatchTyped($event);
	}//end dispatchAudit()

	/**
	 * Exchange a JWT bearer assertion for a short-lived opaque access
	 * token.
	 *
	 * The assertion MUST be a Compact-Serialized JWS signed RS256
	 * (preferred) or ES256 with the application's registered private
	 * key. Required claims: iss (application id), aud="doriath",
	 * exp (>now), iat (<=now+CLOCK_SKEW), jti (unique within TTL).
	 *
	 * @param string $assertion The JWS compact serialization
	 *
	 * @return array{access_token:string,token_type:string,expires_in:int}
	 *
	 * @throws RuntimeException When the assertion is malformed, has
	 *                          invalid claims, fails signature
	 *                          verification, or replays a known jti.
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
	 */
	public function exchangeAssertion(string $assertion): array {
		return $this->issueAccessToken(
			application: $this->verifyAssertion(assertion: $assertion)
		);
	}//end exchangeAssertion()

	/**
	 * Verify a bearer assertion and return the application that signed it.
	 *
	 * Extracted from `exchangeAssertion()` so a caller that needs the PROVEN
	 * IDENTITY without a token can have it — the in-process secret-request seam
	 * authenticates an application by signed proof and has no use for an access
	 * token. Both callers therefore share one implementation of the four guards
	 * below, rather than a second copy that could drift:
	 *
	 *   - claim acceptability (aud, exp, iat within CLOCK_SKEW, jti present),
	 *     which bounds the assertion lifetime
	 *   - jti replay refusal within that lifetime
	 *   - issuer resolution to an ACTIVE registered application, so a pending,
	 *     rejected or deleted application is refused (isActive() is an
	 *     allow-list on STATUS_ACTIVE, so a new status fails closed)
	 *   - signature verification against the application's REGISTERED key, so a
	 *     proof signed by any other key is refused
	 *
	 * The jti is consumed here, which is the point: verification is not
	 * idempotent, and a caller must not be able to replay one proof twice by
	 * calling a different entrypoint.
	 *
	 * @param string $assertion The JWS compact serialization
	 *
	 * @return Application The application that signed the assertion
	 *
	 * @throws RuntimeException When the assertion is empty, malformed, has
	 *                          unacceptable claims, replays a jti, names an
	 *                          inactive issuer, or fails signature verification.
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function verifyAssertion(string $assertion): Application {
		if ($assertion === '') {
			throw new RuntimeException(message: 'Assertion is empty');
		}

		$claims = $this->verifier->readAcceptableClaims(assertion: $assertion);

		// Audience is asserted here rather than inside the verifier: which
		// values this deployment answers to, and for how much longer, is a
		// published contract decision, not a property of a well-formed JWS.
		$usesDeprecated = $this->audiencePolicy->assertNamesThisInstance(claims: $claims);

		$jtiCache = $this->cacheFactory->createDistributed(self::JTI_CACHE_NS);
		$jti = (string)$claims['jti'];
		if ($jtiCache->hasKey($jti) === true) {
			throw new RuntimeException(message: 'Assertion jti replayed');
		}

		$application = $this->loadActiveIssuer(issuer: (string)$claims['iss']);

		$this->verifier->verifySignature(
			assertion: $assertion,
			jwk: $this->keyResolver->forApplication(application: $application)
		);

		// Store jti to prevent replay during max assertion lifetime.
		$jtiCache->set($jti, true, self::ACCESS_TOKEN_TTL);

		// Reported only now: everything above can reject, and an issuer named
		// by a rejected assertion is unverified. Warning earlier would let a
		// forged or replayed assertion manufacture migration traffic for any
		// issuer it cared to name.
		if ($usesDeprecated === true) {
			$this->audiencePolicy->reportDeprecatedUse(claims: $claims);
		}

		return $application;
	}//end verifyAssertion()

	/**
	 * Resolve the `iss` claim to an active registered application.
	 *
	 * @param string $issuer The `iss` claim (an application id)
	 *
	 * @return Application
	 *
	 * @throws RuntimeException When the issuer is unknown or inactive.
	 */
	private function loadActiveIssuer(string $issuer): Application {
		try {
			$application = $this->applicationMapper->findById($issuer);
		} catch (DoesNotExistException) {
			throw new RuntimeException(message: 'Unknown issuer');
		}

		if ($application->isActive() === false) {
			throw new RuntimeException(message: 'Issuer application is not active');
		}

		return $application;
	}//end loadActiveIssuer()

	/**
	 * Mint and cache an opaque access token bound to the application id.
	 *
	 * @param Application $application The verified issuing application
	 *
	 * @return array{access_token:string,token_type:string,expires_in:int}
	 */
	private function issueAccessToken(Application $application): array {
		$accessToken = bin2hex(random_bytes(32));
		$tokenCache = $this->cacheFactory->createDistributed(self::TOKEN_CACHE_NS);
		$tokenCache->set($accessToken, $application->getId(), self::ACCESS_TOKEN_TTL);

		$this->dispatchAudit(
			event: $this->auditEvents->forApplication(
				actorId: $application->getId(),
				eventType: AuditEventTypes::APPLICATION_TOKEN_ISSUED,
				objectType: 'application',
				objectId: $application->getId(),
				objectName: $application->getName(),
			)
		);

		return [
			'access_token' => $accessToken,
			'token_type' => 'Bearer',
			'expires_in' => self::ACCESS_TOKEN_TTL,
		];
	}//end issueAccessToken()

	/**
	 * Validate an opaque access token and resolve the bound application.
	 *
	 * @param string $accessToken The opaque access token from the
	 *                            `Authorization: Bearer <token>` header.
	 *
	 * @return Application|null The bound application, or null when the
	 *                          token is unknown, expired, or the
	 *                          application is no longer active.
	 */
	public function validateAccessToken(string $accessToken): ?Application {
		if ($accessToken === '') {
			return null;
		}

		$tokenCache = $this->cacheFactory->createDistributed(self::TOKEN_CACHE_NS);
		$applicationId = $tokenCache->get($accessToken);

		if (is_string($applicationId) === false || $applicationId === '') {
			return null;
		}

		try {
			$application = $this->applicationMapper->findById($applicationId);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($application->isActive() === false) {
			return null;
		}

		return $application;
	}//end validateAccessToken()
}//end class
