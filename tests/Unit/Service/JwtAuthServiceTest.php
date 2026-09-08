<?php

/**
 * Unit tests for JwtAuthService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Service\ApplicationJwkResolver;
use OCA\Keepiq\Service\JwtAssertionVerifier;
use OCA\Keepiq\Service\JwtAuthService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for JwtAuthService — covers the exchange, signature verification,
 * claim checks, replay protection, and access-token validation paths.
 */
class JwtAuthServiceTest extends TestCase {

	private ApplicationMapper $applicationMapper;

	private EncryptionSuiteMapper $suiteMapper;

	private ICacheFactory $cacheFactory;

	private LoggerInterface $logger;

	private JwtAuthService $service;

	/**
	 * In-memory cache backing the two distributed namespaces.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $cacheStore = ['keepiq_jwt_jti' => [], 'keepiq_jwt_token' => []];

	/**
	 * A self-signed certificate (PEM) the test app "owns".
	 */
	private string $certificatePem;

	/**
	 * Matching private key (PEM) used to sign assertions in the test.
	 */
	private string $privateKeyPem;

	/**
	 * JWK derived from $privateKeyPem (for signing).
	 */
	private JWK $signingKey;

	/**
	 * Set up: generate a self-signed RSA cert, wire mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->applicationMapper = $this->createMock(ApplicationMapper::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->cacheFactory->method('createDistributed')
			->willReturnCallback(fn (string $ns) => $this->buildInMemoryCache($ns));

		// Generate a fresh self-signed RSA certificate (4096-bit) per test run.
		$config = [
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
			'private_key_bits' => 2048,
		];
		$pkey = openssl_pkey_new($config);
		$csr = openssl_csr_new(
			['CN' => 'test-app', 'O' => 'keepiq-tests'],
			$pkey,
			$config
		);
		$cert = openssl_csr_sign($csr, null, $pkey, 1, $config);
		openssl_x509_export($cert, $certPem);
		openssl_pkey_export($pkey, $privPem);
		$this->certificatePem = $certPem;
		$this->privateKeyPem = $privPem;
		$this->signingKey = JWKFactory::createFromKey($privPem);

		$this->service = new JwtAuthService(
			applicationMapper: $this->applicationMapper,
			cacheFactory: $this->cacheFactory,
			verifier: new JwtAssertionVerifier(logger: $this->logger),
			keyResolver: new ApplicationJwkResolver(suiteMapper: $this->suiteMapper),
		);
	}//end setUp()

	/**
	 * Build an in-memory cache for the given namespace, backed by
	 * $this->cacheStore.
	 *
	 * @param string $ns The cache namespace
	 *
	 * @return ICache
	 */
	private function buildInMemoryCache(string $ns): ICache {
		if (array_key_exists($ns, $this->cacheStore) === false) {
			$this->cacheStore[$ns] = [];
		}

		$store = &$this->cacheStore[$ns];
		$cache = $this->createMock(ICache::class);

		$cache->method('hasKey')->willReturnCallback(
			static fn (string $k) => array_key_exists($k, $store)
		);
		$cache->method('get')->willReturnCallback(
			static fn (string $k) => $store[$k] ?? null
		);
		$cache->method('set')->willReturnCallback(
			static function (string $k, mixed $v) use (&$store): bool {
				$store[$k] = $v;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			static function (string $k) use (&$store): bool {
				unset($store[$k]);
				return true;
			}
		);

		return $cache;
	}//end buildInMemoryCache()

	/**
	 * Build a Compact-serialized JWS with the test signing key.
	 *
	 * @param array<string,mixed> $claims The claims
	 * @param string|null $alg The algorithm header (RS256 default)
	 *
	 * @return string
	 */
	private function buildAssertion(array $claims, ?string $alg = 'RS256'): string {
		$algorithmManager = new AlgorithmManager([new RS256()]);
		$builder = new JWSBuilder($algorithmManager);

		$jws = $builder->create()
			->withPayload(json_encode($claims))
			->addSignature($this->signingKey, ['alg' => $alg, 'typ' => 'JWT'])
			->build();

		return (new CompactSerializer())->serialize($jws, 0);
	}//end buildAssertion()

	/**
	 * Stub the application + suite lookups with an active app whose
	 * certificate matches the signing key.
	 *
	 * @param string $appId The application ID
	 *
	 * @return void
	 */
	private function stubActiveApp(string $appId = 'app-1'): void {
		$app = new Application();
		$app->setId($appId);
		$app->setStatus(Application::STATUS_ACTIVE);
		$app->setName('Test');
		$app->setType(Application::TYPE_INTERNAL);

		$suite = new EncryptionSuite();
		$suite->setOwnerType('application');
		$suite->setOwnerId($appId);
		$suite->setCertificate($this->certificatePem);
		$suite->setStatus('active');

		$this->applicationMapper->method('findById')->willReturn($app);
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);
	}//end stubActiveApp()

	/**
	 * A valid assertion exchanges for a Bearer access token.
	 *
	 * @return void
	 */
	public function testValidAssertionExchanges(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'doriath',
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-1',
			]
		);

		$result = $this->service->exchangeAssertion($assertion);

		$this->assertSame('Bearer', $result['token_type']);
		$this->assertSame(300, $result['expires_in']);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['access_token']);
		// jti recorded for replay protection.
		$this->assertArrayHasKey('jti-1', $this->cacheStore['keepiq_jwt_jti']);
	}//end testValidAssertionExchanges()

	/**
	 * A signature mismatch fails the verifier — different signing key.
	 *
	 * @return void
	 */
	public function testInvalidSignatureRejected(): void {
		$this->stubActiveApp('app-1');

		// Sign with a fresh, unrelated key so the certificate's public key
		// can't verify it.
		$otherPkey = openssl_pkey_new(
			['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
		);
		openssl_pkey_export($otherPkey, $otherPem);
		$otherKey = JWKFactory::createFromKey($otherPem);
		$algorithmManager = new AlgorithmManager([new RS256()]);
		$builder = new JWSBuilder($algorithmManager);
		$now = time();
		$jws = $builder->create()
			->withPayload(
				json_encode(
					[
						'iss' => 'app-1',
						'aud' => 'doriath',
						'iat' => $now,
						'exp' => ($now + 60),
						'jti' => 'jti-evil',
					]
				)
			)
			->addSignature($otherKey, ['alg' => 'RS256', 'typ' => 'JWT'])
			->build();
		$assertion = (new CompactSerializer())->serialize($jws, 0);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('signature verification failed');
		$this->service->exchangeAssertion($assertion);
	}//end testInvalidSignatureRejected()

	/**
	 * An expired assertion is rejected.
	 *
	 * @return void
	 */
	public function testExpiredAssertionRejected(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'doriath',
				'iat' => ($now - 600),
				'exp' => ($now - 300),
				'jti' => 'jti-expired',
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('expired');
		$this->service->exchangeAssertion($assertion);
	}//end testExpiredAssertionRejected()

	/**
	 * An assertion with iat far in the future is rejected.
	 *
	 * @return void
	 */
	public function testFutureIatRejected(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'doriath',
				'iat' => ($now + 600),
				'exp' => ($now + 1200),
				'jti' => 'jti-future',
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('iat in future');
		$this->service->exchangeAssertion($assertion);
	}//end testFutureIatRejected()

	/**
	 * Wrong audience claim is rejected.
	 *
	 * @return void
	 */
	public function testWrongAudienceRejected(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'someoneelse',
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-aud',
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Wrong audience');
		$this->service->exchangeAssertion($assertion);
	}//end testWrongAudienceRejected()

	/**
	 * The canonical audience is accepted and raises no deprecation warning.
	 *
	 * @return void
	 */
	public function testCanonicalAudienceAccepted(): void {
		$this->stubActiveApp('app-1');

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'keepiq',
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-aud-canonical',
			]
		);

		$result = $this->service->exchangeAssertion($assertion);

		$this->assertSame('Bearer', $result['token_type']);
		$this->assertSame([], $warnings, 'the canonical audience must not be reported as deprecated');
	}//end testCanonicalAudienceAccepted()

	/**
	 * The pre-rename audience still works, and is reported with its issuer.
	 *
	 * Rejecting it would be a fleet-wide credential outage; accepting it
	 * silently would leave nobody knowing who still has to migrate before
	 * the value is retired.
	 *
	 * @return void
	 */
	public function testDeprecatedAudienceAcceptedAndReported(): void {
		$this->stubActiveApp('app-1');

		$context = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $ctx = []) use (&$context): void {
				$context = $ctx;
			}
		);

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'doriath',
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-aud-deprecated',
			]
		);

		$result = $this->service->exchangeAssertion($assertion);

		$this->assertSame('Bearer', $result['token_type'], 'the deprecated audience must still exchange');
		$this->assertSame('app-1', $context['iss'] ?? null, 'the warning must name the issuer still to migrate');
		$this->assertSame('doriath', $context['deprecated'] ?? null);
		$this->assertSame(2, $context['version'] ?? null, 'the warning must name the retiring apiVersion');
	}//end testDeprecatedAudienceAcceptedAndReported()

	/**
	 * An array-valued `aud` is accepted when any member names this instance.
	 *
	 * RFC 7519 §4.1.3 permits `aud` to be an array; a conformant client
	 * sending one used to be rejected outright.
	 *
	 * @return void
	 */
	public function testArrayValuedAudienceAccepted(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => ['someoneelse', 'keepiq'],
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-aud-array',
			]
		);

		$result = $this->service->exchangeAssertion($assertion);

		$this->assertSame('Bearer', $result['token_type']);
	}//end testArrayValuedAudienceAccepted()

	/**
	 * An array-valued `aud` naming only foreign audiences is rejected.
	 *
	 * @return void
	 */
	public function testArrayValuedForeignAudienceRejected(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => ['someoneelse', 'anotherapp'],
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-aud-array-foreign',
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Wrong audience');
		$this->service->exchangeAssertion($assertion);
	}//end testArrayValuedForeignAudienceRejected()

	/**
	 * Replayed jti is rejected on second use.
	 *
	 * @return void
	 */
	public function testJtiReplayRejected(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'doriath',
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-replay',
			]
		);

		$this->service->exchangeAssertion($assertion);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('replayed');
		$this->service->exchangeAssertion($assertion);
	}//end testJtiReplayRejected()

	/**
	 * Unknown issuer is rejected.
	 *
	 * @return void
	 */
	public function testUnknownIssuerRejected(): void {
		$this->applicationMapper->method('findById')
			->willThrowException(new DoesNotExistException('no'));

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-unknown',
				'aud' => 'doriath',
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-unknown',
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown issuer');
		$this->service->exchangeAssertion($assertion);
	}//end testUnknownIssuerRejected()

	/**
	 * Inactive application is rejected.
	 *
	 * @return void
	 */
	public function testInactiveApplicationRejected(): void {
		$app = new Application();
		$app->setId('app-1');
		$app->setStatus(Application::STATUS_PENDING);
		$app->setName('Pending');
		$app->setType(Application::TYPE_INTERNAL);

		$this->applicationMapper->method('findById')->willReturn($app);

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'doriath',
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-inactive',
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not active');
		$this->service->exchangeAssertion($assertion);
	}//end testInactiveApplicationRejected()

	/**
	 * validateAccessToken returns the bound application for a stored token.
	 *
	 * @return void
	 */
	public function testValidateAccessTokenSuccess(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'doriath',
				'iat' => $now,
				'exp' => ($now + 60),
				'jti' => 'jti-token',
			]
		);
		$result = $this->service->exchangeAssertion($assertion);

		$app = $this->service->validateAccessToken($result['access_token']);
		$this->assertNotNull($app);
		$this->assertSame('app-1', $app->getId());
	}//end testValidateAccessTokenSuccess()

	/**
	 * validateAccessToken returns null for unknown tokens.
	 *
	 * @return void
	 */
	public function testValidateAccessTokenUnknownReturnsNull(): void {
		$this->assertNull($this->service->validateAccessToken('not-a-real-token'));
	}//end testValidateAccessTokenUnknownReturnsNull()

	/**
	 * validateAccessToken returns null for an empty token.
	 *
	 * @return void
	 */
	public function testValidateAccessTokenEmptyReturnsNull(): void {
		$this->assertNull($this->service->validateAccessToken(''));
	}//end testValidateAccessTokenEmptyReturnsNull()

	/**
	 * An assertion whose lifetime (exp - iat) exceeds the 300-second
	 * maximum is rejected (secret-store-api token hardening D7).
	 *
	 * @return void
	 */
	public function testOverlongAssertionLifetimeRejected(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'doriath',
				'iat' => $now,
				'exp' => ($now + 3600),
				'jti' => 'jti-long',
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('lifetime exceeds');
		$this->service->exchangeAssertion($assertion);
	}//end testOverlongAssertionLifetimeRejected()

	/**
	 * An assertion whose lifetime is exactly the 300-second maximum is
	 * accepted (boundary).
	 *
	 * @return void
	 */
	public function testAssertionLifetimeAtMaximumAccepted(): void {
		$this->stubActiveApp('app-1');

		$now = time();
		$assertion = $this->buildAssertion(
			[
				'iss' => 'app-1',
				'aud' => 'doriath',
				'iat' => $now,
				'exp' => ($now + 300),
				'jti' => 'jti-boundary',
			]
		);

		$result = $this->service->exchangeAssertion($assertion);
		$this->assertSame('Bearer', $result['token_type']);
	}//end testAssertionLifetimeAtMaximumAccepted()
}//end class
