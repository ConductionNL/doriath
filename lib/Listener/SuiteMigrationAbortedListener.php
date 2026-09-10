<?php

/**
 * Keepiq Suite Migration Aborted Listener
 *
 * Responds to a compromise-recovery migration being aborted by releasing the
 * SecretRequests that SuiteMigrationStartedListener locked when the migration
 * began — keeping them on the OLD suite, because an abort returns the vault to
 * that suite rather than migrating to the new one.
 *
 * This is deliberately the ONLY reaction to an abort. The completion listeners
 * (compromise-flagging, link-share revocation, emergency-access invalidation)
 * must not run: nothing was migrated, the old suite stays active, and firing
 * them would tear down state the abort exists to preserve.
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

use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Service\SecretRequestSuiteLockService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Unlock SecretRequests, keeping the old suite, when a migration is aborted.
 *
 * @implements IEventListener<SuiteMigrationAbortedEvent>
 */
class SuiteMigrationAbortedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SecretRequestSuiteLockService $secretRequestService The SecretRequest suite-lock service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SecretRequestSuiteLockService $secretRequestService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-can-be-aborted-before-any-record-moves
	 */
	public function handle(Event $event): void {
		if (($event instanceof SuiteMigrationAbortedEvent) === false) {
			return;
		}

		try {
			// Unlock the requests locked at start, keeping them on the OLD
			// suite: passing the old id as both arguments re-points them to the
			// suite they are already on (a no-op update) and flips their status
			// back to pending. The new suite is being discarded, so it must not
			// become their target.
			$unlocked = $this->secretRequestService->unlockAndUpdateSuite(
				$event->getOldSuiteId(),
				$event->getOldSuiteId()
			);
			$this->logger->info(
				'Keepiq: unlocked SecretRequests after migration abort, kept on the old suite',
				[
					'oldSuiteId' => $event->getOldSuiteId(),
					'unlocked' => $unlocked,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Keepiq: SuiteMigrationAbortedListener failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end handle()
}//end class
