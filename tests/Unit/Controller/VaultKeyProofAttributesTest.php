<?php

/**
 * Unit tests asserting that every operation which can render vault contents or
 * key material permanently unreadable carries #[VaultKeyProofRequired] with the
 * expected binding, subject and purpose.
 *
 * A declarative guard fails OPEN when it is omitted: nothing errors, the guard
 * is simply absent. This reflection-based suite is the fail-closed backstop — a
 * destructive route that drops the attribute, or has its binding/subject/purpose
 * quietly loosened, turns the build red. It cannot exercise the middleware for a
 * real 403 (that needs a running instance with its request pipeline), the same
 * rationale documented for RateLimitAttributesTest.
 *
 * Adding a route that can irreversibly destroy vault data without adding it to
 * `guardedMethodsProvider` is a defect in this test, not an accepted gap.
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

use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Controller\EmergencyAccessController;
use OCA\Keepiq\Controller\EncryptionSuiteController;
use OCA\Keepiq\Controller\MigrationController;
use OCA\Keepiq\Service\VaultKeyProofService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for #[VaultKeyProofRequired] coverage on the destructive endpoints.
 */
class VaultKeyProofAttributesTest extends TestCase {
	/**
	 * Each destructive operation with its expected binds, subject and purpose.
	 *
	 * @return array<string,array{0:class-string,1:string,2:string[],3:string,4:string}>
	 */
	public static function guardedMethodsProvider(): array {
		return [
			'compromise recovery' => [
				EncryptionSuiteController::class,
				'compromiseRecovery',
				['publicKey', 'encryptedPrivateKey'],
				'active',
				VaultKeyProofService::PURPOSE_COMPROMISE_RECOVERY,
			],
			'update private key' => [
				EncryptionSuiteController::class,
				'updatePrivateKey',
				['encryptedPrivateKey'],
				'routeParam:id',
				VaultKeyProofService::PURPOSE_UPDATE_PRIVATE_KEY,
			],
			'complete migration' => [
				MigrationController::class,
				'complete',
				['id'],
				'migrationOldSuite',
				VaultKeyProofService::PURPOSE_COMPLETE_MIGRATION,
			],
			'destroy emergency contact' => [
				EmergencyAccessController::class,
				'destroy',
				['id'],
				'active',
				VaultKeyProofService::PURPOSE_EMERGENCY_DESTROY,
			],
		];
	}//end guardedMethodsProvider()

	/**
	 * @dataProvider guardedMethodsProvider
	 *
	 * @param class-string $class The controller class
	 * @param string $method The method name
	 * @param string[] $binds The expected bound parameters
	 * @param string $subject The expected subject resolution
	 * @param string $purpose The expected purpose
	 *
	 * @return void
	 */
	public function testDestructiveMethodCarriesTheGuard(
		string $class,
		string $method,
		array $binds,
		string $subject,
		string $purpose,
	): void {
		$attributes = (new ReflectionMethod($class, $method))
			->getAttributes(VaultKeyProofRequired::class);

		$this->assertCount(
			1,
			$attributes,
			"$class::$method must carry exactly one #[VaultKeyProofRequired]"
		);

		$attribute = $attributes[0]->newInstance();
		$this->assertSame($binds, $attribute->getBinds(), "$class::$method binds");
		$this->assertSame($subject, $attribute->getSubject(), "$class::$method subject");
		$this->assertSame($purpose, $attribute->getPurpose(), "$class::$method purpose");
	}//end testDestructiveMethodCarriesTheGuard()

	/**
	 * Methods deliberately NOT guarded, with the reason each is safe.
	 *
	 * @return array<string,array{0:class-string,1:string,2:string}>
	 */
	public static function deliberatelyUnguardedProvider(): array {
		return [
			'proof challenge' => [
				EncryptionSuiteController::class,
				'proofChallenge',
				'Issuing a challenge grants nothing on its own; guarding it would be circular.',
			],
			'abort migration' => [
				MigrationController::class,
				'abort',
				'Abort is restorative — it returns the vault to the still-active old suite. '
				. 'Requiring a proof would leave a vault wedged by an unauthorised rotation wedged.',
			],
		];
	}//end deliberatelyUnguardedProvider()

	/**
	 * @dataProvider deliberatelyUnguardedProvider
	 *
	 * @param class-string $class The controller class
	 * @param string $method The method name
	 * @param string $reason Why it is safe unguarded (documentation)
	 *
	 * @return void
	 */
	public function testDeliberatelyUnguardedMethodHasNoGuard(
		string $class,
		string $method,
		string $reason,
	): void {
		$attributes = (new ReflectionMethod($class, $method))
			->getAttributes(VaultKeyProofRequired::class);

		$this->assertCount(0, $attributes, "$class::$method must stay unguarded — $reason");
	}//end testDeliberatelyUnguardedMethodHasNoGuard()
}//end class
