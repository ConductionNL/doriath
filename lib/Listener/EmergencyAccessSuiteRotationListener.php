<?php

/**
 * Keepiq EmergencyAccessSuiteRotationListener
 *
 * Listens for SuiteMigrationCompletedEvent (compromise recovery / key rotation)
 * and invalidates the grantor's emergency-access recovery envelopes
 * (add-emergency-access §3.1 / design D6). The envelopes escrow the grantor's
 * OLD private key, so after a rotation they hold a stale key and MUST be
 * invalidated; the grantor is then prompted (in the UI) to re-establish
 * emergency access against the new key. The envelope is keyed by the old suite
 * id recorded at designation.
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Invalidate a grantor's emergency-access envelopes on suite rotation.
 *
 * @implements IEventListener<SuiteMigrationCompletedEvent>
 *
 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
 */
class EmergencyAccessSuiteRotationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param EmergencyEnvelopeInvalidationService $service The envelope-invalidation service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private EmergencyEnvelopeInvalidationService $service,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the SuiteMigrationCompletedEvent.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function handle(Event $event): void {
		if ($event instanceof SuiteMigrationCompletedEvent === false) {
			return;
		}

		try {
			// Residual SWEEP, not a blanket invalidation. The migration loop has
			// already re-enveloped every reachable contact onto the new suite
			// (MigrationController::reEnvelopeEmergencyContact), so those rows no
			// longer sit on the old suite and this pass skips them. What remains on
			// the old suite is exactly the residual the browser could not carry —
			// a grantee with no active certificate to seal to — which genuinely
			// must be invalidated. Do NOT "optimise away" this apparent no-op: on a
			// rotation with an unreachable grantee it is the only thing that clears
			// the stale envelope. The envelope escrows the OLD suite's private key.
			$this->service->invalidateForGrantorRotation(
				grantorSuiteId: $event->getOldSuiteId(),
				reason: 'grantor_rotation',
			);
		} catch (Throwable $e) {
			$this->logger->error('Keepiq: emergency-access rotation invalidation failed: ' . $e->getMessage());
		}
	}//end handle()
}//end class
