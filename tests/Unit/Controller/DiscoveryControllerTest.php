<?php

/**
 * Unit tests for DiscoveryController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\DiscoveryController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the public machine API discovery document.
 */
class DiscoveryControllerTest extends TestCase {

	private DiscoveryController $controller;

	/**
	 * Mocked logger, for the deprecated-path warning.
	 *
	 * @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Wire the controller with a URL generator that echoes route names.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturnCallback(
			static function (string $route): string {
				return match ($route) {
					'keepiq.applicationToken.exchange' => '/apps/keepiq/api/v1/token',
					'keepiq.applicationSecrets.index' => '/apps/keepiq/api/v1/app/secrets',
					default => '/apps/keepiq/' . $route,
				};
			}
		);
		$url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $p) => 'https://nc.test' . $p
		);

		$this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$this->controller = new DiscoveryController(
			request: $request,
			urlGenerator: $url,
			appConfig: null,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * The document declares the API version, token endpoint, grant type,
	 * assertion requirements, secret endpoints, and envelope formats.
	 *
	 * @return void
	 */
	public function testDocumentShape(): void {
		$response = $this->controller->document();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertSame(1, $data['apiVersion']);
		$this->assertSame('/apps/keepiq/api/v1/token', $data['tokenEndpoint']);
		$this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $data['grantType']);
		$this->assertSame('RS256', $data['assertion']['alg']);
		$this->assertSame(300, $data['assertion']['maxLifetime']);
		$this->assertArrayHasKey('byName', $data['secrets']);
		$this->assertContains('doriath-machine-secret-v1', $data['envelopeFormats']);
	}//end testDocumentShape()

	/**
	 * The document contains no instance-private data (no keys, certs,
	 * user ids, or secret values).
	 *
	 * @return void
	 */
	public function testNoInstancePrivateData(): void {
		$flat = json_encode($this->controller->document()->getData());
		foreach (['privateKey', 'certificate', 'BEGIN', 'password', 'userId'] as $needle) {
			$this->assertStringNotContainsString($needle, $flat);
		}
	}//end testNoInstancePrivateData()
	/**
	 * Both discovery paths return the identical document.
	 *
	 * The deprecated path is the one URL consumers hold by hand, so it must
	 * keep working byte-for-byte until it is removed before the first stable release.
	 *
	 * @return void
	 */
	public function testDeprecatedPathServesTheIdenticalDocument(): void {
		$canonical = $this->controller->document()->getData();
		$legacy = $this->controller->legacyDocument()->getData();

		$this->assertSame($canonical, $legacy);
	}//end testDeprecatedPathServesTheIdenticalDocument()

	/**
	 * The document names the canonical path and the deprecated one.
	 *
	 * @return void
	 */
	public function testDocumentAdvertisesDiscoveryPaths(): void {
		$data = $this->controller->document()->getData();

		$this->assertSame('/api/v1/app/.well-known/keepiq', $data['discoveryPath']);
		$this->assertSame(
			[['value' => '/api/v1/app/.well-known/doriath', 'removedInAppVersion' => '1.0.0']],
			$data['deprecatedDiscoveryPaths']
		);
	}//end testDocumentAdvertisesDiscoveryPaths()

	/**
	 * Fetching the deprecated path reports it, naming the retiring version.
	 *
	 * @return void
	 */
	public function testDeprecatedPathIsReported(): void {
		$context = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $ctx = []) use (&$context): void {
				$context = $ctx;
			}
		);

		$this->controller->legacyDocument();

		$this->assertSame('/api/v1/app/.well-known/doriath', $context['deprecated'] ?? null);
		$this->assertSame('/api/v1/app/.well-known/keepiq', $context['canonical'] ?? null);
		$this->assertSame('1.0.0', $context['version'] ?? null);
	}//end testDeprecatedPathIsReported()

	/**
	 * The successor envelope format is announced but not yet emitted.
	 *
	 * `envelopeFormats` declares what actually goes on the wire; listing a
	 * format nothing writes would be a claim a consumer could act on.
	 *
	 * @return void
	 */
	public function testUpcomingEnvelopeFormatIsAnnouncedNotEmitted(): void {
		$data = $this->controller->document()->getData();

		$this->assertSame(['doriath-machine-secret-v1'], $data['envelopeFormats']);
		$this->assertSame(
			[[
				'value' => 'keepiq-machine-secret-v1',
				'replaces' => 'doriath-machine-secret-v1',
				'emittedFromAppVersion' => '1.0.0',
			]],
			$data['upcomingEnvelopeFormats']
		);
	}//end testUpcomingEnvelopeFormatIsAnnouncedNotEmitted()
}//end class
