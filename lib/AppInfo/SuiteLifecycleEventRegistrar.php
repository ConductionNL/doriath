<?php

/**
 * Keepiq suite-lifecycle event registrar
 *
 * Binds every listener that reacts to an EncryptionSuite changing state:
 * compromise-recovery migration start/finish, suite revocation, and the
 * emergency-access envelopes those two invalidate.
 *
 * @category AppInfo
 * @package  OCA\Keepiq\AppInfo
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

namespace OCA\Keepiq\AppInfo;

use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Event\SuiteMigrationStartedEvent;
use OCA\Keepiq\Listener\EmergencyAccessSuiteRevocationListener;
use OCA\Keepiq\Listener\EmergencyAccessSuiteRotationListener;
use OCA\Keepiq\Listener\EncryptionSuiteRevokedListener;
use OCA\Keepiq\Listener\SuiteCompromiseListener;
use OCA\Keepiq\Listener\SuiteMigrationAbortedListener;
use OCA\Keepiq\Listener\SuiteMigrationCompletedListener;
use OCA\Keepiq\Listener\SuiteMigrationStartedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the EncryptionSuite lifecycle listener graph.
 *
 * The three suite events fan out to more than one listener each, and the
 * ORDER of the bindings is not significant — Nextcloud's dispatcher invokes
 * every registered listener for an event and a failure in one is contained by
 * that listener, not by this registration.
 *
 * Grouped as one registrar because all six listeners share a single trigger
 * family (a suite started migrating, finished migrating, or was revoked) and
 * a single invariant: no ciphertext may survive a suite it can no longer be
 * decrypted under.
 */
final class SuiteLifecycleEventRegistrar {
	/**
	 * Bind the suite-lifecycle listeners to their events.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// Compromise-recovery: lock SecretRequests when migration starts and
		// unlock + re-suite them when it completes
		// (implement-secret-requests §6.1-6.3).
		$context->registerEventListener(
			event: SuiteMigrationStartedEvent::class,
			listener: SuiteMigrationStartedListener::class
		);
		$context->registerEventListener(
			event: SuiteMigrationCompletedEvent::class,
			listener: SuiteMigrationCompletedListener::class
		);

		// Abort: release the SecretRequests locked at start, keeping them on the
		// old suite. Deliberately bound ONLY to this listener — none of the
		// terminal-cascade listeners above may react to an abort, since nothing
		// migrated and the old suite stays active.
		$context->registerEventListener(
			event: SuiteMigrationAbortedEvent::class,
			listener: SuiteMigrationAbortedListener::class
		);

		// Implement-user-sharing §8 — sharing-graph reactions to suite
		// revocation and post-migration possibly-compromised flagging.
		$context->registerEventListener(
			event: EncryptionSuiteRevokedEvent::class,
			listener: EncryptionSuiteRevokedListener::class
		);
		$context->registerEventListener(
			event: SuiteMigrationCompletedEvent::class,
			listener: SuiteCompromiseListener::class
		);

		// Emergency access — invalidate/clear recovery envelopes on a grantor's
		// suite rotation (compromise recovery) or revocation, and invalidate
		// envelopes to a grantee whose suite is revoked (add-emergency-access §3).
		$context->registerEventListener(
			event: SuiteMigrationCompletedEvent::class,
			listener: EmergencyAccessSuiteRotationListener::class
		);
		$context->registerEventListener(
			event: EncryptionSuiteRevokedEvent::class,
			listener: EmergencyAccessSuiteRevocationListener::class
		);

	}//end register()
}//end class
