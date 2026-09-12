<?php

/**
 * Enforces the deadline on the pre-rename compatibility shims.
 *
 * `Application::PRE_STABLE_COMPAT_REMOVED_IN` names the app version by which
 * three shims must be gone: acceptance of the legacy `aud` value, service of
 * the legacy discovery path, and emission of the legacy envelope format name.
 * Every other reference to that constant is a value PUBLISHED to consumers —
 * `removedInAppVersion`, `emittedFromAppVersion` — so until this suite existed
 * the deadline was a promise made to integrators that nothing in the codebase
 * could keep. It would have passed in silence, on an auth surface, leaving a
 * deprecated audience accepted indefinitely.
 *
 * This turns it into a build failure at the boundary. It is not a runtime
 * check on purpose: the shims are cheap and correct while they are supposed to
 * exist, and refusing to boot an instance over a calendar is worse than
 * refusing to ship one.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\AppInfo;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Controller\DiscoveryController;
use OCA\Keepiq\Service\AudiencePolicy;
use OCA\Keepiq\Service\MachineSecretEnvelopeService;
use PHPUnit\Framework\TestCase;

/**
 * Fails the build once the shims outlive their stated deadline.
 */
class PreStableCompatDeadlineTest extends TestCase {

	/**
	 * The app version declared in appinfo/info.xml.
	 *
	 * @return string The version.
	 */
	private function appVersion(): string {
		// Read as text rather than through simplexml: the parser behaves
		// differently under the suite's error handling, and one element does
		// not justify depending on that.
		$path = dirname(__DIR__, 3) . '/appinfo/info.xml';
		$xml = file_get_contents($path);

		$this->assertIsString($xml, 'appinfo/info.xml must be readable at ' . $path);
		$this->assertSame(
			1,
			preg_match('#<version>([^<]+)</version>#', $xml, $matches),
			'appinfo/info.xml must declare a <version>'
		);

		return trim($matches[1]);
	}//end appVersion()

	/**
	 * The shims still present in the codebase, named for a failure message.
	 *
	 * Each entry is a thing that must not exist once the deadline is reached.
	 *
	 * @return string[] The surviving shims.
	 */
	private function survivingShims(): array {
		$surviving = [];

		if (defined(AudiencePolicy::class . '::DEPRECATED_AUDIENCE') === true) {
			$surviving[] = sprintf(
				'AudiencePolicy::DEPRECATED_AUDIENCE (%s is still accepted on the token endpoint)',
				AudiencePolicy::DEPRECATED_AUDIENCE
			);
		}

		if (defined(DiscoveryController::class . '::DEPRECATED_DISCOVERY_PATH') === true) {
			$surviving[] = sprintf(
				'DiscoveryController::DEPRECATED_DISCOVERY_PATH (%s is still served)',
				DiscoveryController::DEPRECATED_DISCOVERY_PATH
			);
		}

		if (str_starts_with(MachineSecretEnvelopeService::FORMAT, 'doriath-') === true) {
			$surviving[] = sprintf(
				'MachineSecretEnvelopeService::FORMAT (still emitting %s instead of %s)',
				MachineSecretEnvelopeService::FORMAT,
				MachineSecretEnvelopeService::UPCOMING_FORMAT
			);
		}

		return $surviving;
	}//end survivingShims()

	/**
	 * The shims are gone by the version that says they will be.
	 *
	 * Below the deadline this asserts the opposite — that the shims are still
	 * findable — so the test cannot quietly stop guarding anything if a
	 * constant is renamed out from under it. A deadline test that silently
	 * matches nothing is worse than no deadline test.
	 *
	 * @return void
	 */
	public function testTheShimsAreGoneByTheirStatedDeadline(): void {
		$version = $this->appVersion();
		$deadline = Application::PRE_STABLE_COMPAT_REMOVED_IN;
		$surviving = $this->survivingShims();

		if (version_compare($version, $deadline, '>=') === true) {
			$this->assertSame(
				[],
				$surviving,
				sprintf(
					"App version %s has reached the declared removal version %s, so these must go:\n  - %s\n\n"
					. 'Each is published to consumers as removed at %s. Shipping past it makes the discovery '
					. 'document untrue and leaves a deprecated audience accepted on the token endpoint.',
					$version,
					$deadline,
					implode("\n  - ", $surviving),
					$deadline
				)
			);

			return;
		}

		$this->assertNotSame(
			[],
			$surviving,
			sprintf(
				'This test guards three shims and can no longer find any of them, while the app is still at '
				. '%s (below %s). Either they were removed early - in which case delete this test and the '
				. 'deadline constant - or they were renamed and this guard now watches nothing.',
				$version,
				$deadline
			)
		);
	}//end testTheShimsAreGoneByTheirStatedDeadline()

	/**
	 * Everything published as removed at the deadline names the same version.
	 *
	 * Three constants feed three separate fields of the discovery document. If
	 * they drift, consumers are told different removal dates for shims that go
	 * together.
	 *
	 * @return void
	 */
	public function testEveryPublishedDeadlineNamesTheSameVersion(): void {
		$this->assertSame(
			Application::PRE_STABLE_COMPAT_REMOVED_IN,
			AudiencePolicy::DEPRECATED_AUDIENCE_REMOVED_IN,
			'the audience deadline must not drift from the shared one'
		);
		$this->assertSame(
			Application::PRE_STABLE_COMPAT_REMOVED_IN,
			DiscoveryController::DEPRECATED_PATH_REMOVED_IN,
			'the discovery-path deadline must not drift from the shared one'
		);
		$this->assertSame(
			Application::PRE_STABLE_COMPAT_REMOVED_IN,
			MachineSecretEnvelopeService::UPCOMING_FORMAT_APP_VERSION,
			'the envelope-format deadline must not drift from the shared one'
		);
	}//end testEveryPublishedDeadlineNamesTheSameVersion()
}//end class
