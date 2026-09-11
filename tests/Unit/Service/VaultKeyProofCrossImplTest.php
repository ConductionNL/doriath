<?php

/**
 * Cross-implementation test for the vault-key proof.
 *
 * Config rule: test cross-implementation crypto round-trips. The signature in
 * tests/fixtures/vault-key-proof.json is produced by the BROWSER's scheme
 * (WebCrypto RSASSA-PKCS1-v1_5 SHA-256, the same one
 * src/crypto/reauth.js#proveMasterPassword uses) over the message
 * VaultKeyProofService::signedMessage builds. This asserts PHP openssl verifies
 * it — i.e. a proof a real browser makes will pass on the server. The nonce HMAC
 * and expiry machinery are covered by VaultKeyProofServiceTest; the interop risk
 * that only a cross-language test catches is the signature + message
 * construction, which is exactly what this isolates.
 *
 * Regenerate the fixture with tests/fixtures/generate-vault-key-proof-fixture.mjs.
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

use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Verifies a browser-produced signature on the PHP side.
 */
class VaultKeyProofCrossImplTest extends TestCase {
	public function testABrowserProducedSignatureVerifiesInPhp(): void {
		$fixture = json_decode(
			(string)file_get_contents(__DIR__ . '/../../fixtures/vault-key-proof.json'),
			true
		);
		$this->assertIsArray($fixture, 'fixture must load');

		// signedMessage() is deterministic and needs no live collaborators.
		$service = new VaultKeyProofService(
			config: $this->createMock(IConfig::class),
			secureRandom: $this->createMock(ISecureRandom::class),
			timeFactory: $this->createMock(ITimeFactory::class),
		);

		$message = $service->signedMessage(
			nonce: $fixture['nonce'],
			boundValues: $fixture['boundValues']
		);

		$verified = openssl_verify(
			$message,
			base64_decode($fixture['signatureB64'], true),
			$fixture['publicKeyPem'],
			OPENSSL_ALGO_SHA256
		);

		$this->assertSame(
			1,
			$verified,
			'A browser (WebCrypto) RSASSA-PKCS1-v1_5 SHA-256 signature must verify with PHP openssl '
			. 'over the exact signedMessage() construction'
		);
	}//end testABrowserProducedSignatureVerifiesInPhp()

	public function testATamperedBoundValueBreaksCrossImplVerification(): void {
		$fixture = json_decode(
			(string)file_get_contents(__DIR__ . '/../../fixtures/vault-key-proof.json'),
			true
		);

		$service = new VaultKeyProofService(
			config: $this->createMock(IConfig::class),
			secureRandom: $this->createMock(ISecureRandom::class),
			timeFactory: $this->createMock(ITimeFactory::class),
		);

		// Same signature, but the server rebuilds the message with a changed
		// bound value — the binding the guard relies on.
		$message = $service->signedMessage(
			nonce: $fixture['nonce'],
			boundValues: ['TAMPERED', $fixture['boundValues'][1]]
		);

		$verified = openssl_verify(
			$message,
			base64_decode($fixture['signatureB64'], true),
			$fixture['publicKeyPem'],
			OPENSSL_ALGO_SHA256
		);

		$this->assertNotSame(1, $verified);
	}//end testATamperedBoundValueBreaksCrossImplVerification()
}//end class
