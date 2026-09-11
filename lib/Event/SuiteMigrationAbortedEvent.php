<?php

/**
 * Keepiq Suite Migration Aborted Event
 *
 * Dispatched when a compromise-recovery migration is aborted before any record
 * has been committed to the new suite. Distinct from
 * SuiteMigrationCompletedEvent: an abort returns the vault to the OLD suite,
 * which stays active, so its listeners must NOT run the terminal cascade
 * (compromise-flagging, link-share revocation, emergency-access invalidation).
 * The only reaction it carries is releasing the SecretRequests that
 * SuiteMigrationStartedListener locked, keeping them on the old suite.
 *
 * @category Event
 * @package  OCA\Keepiq\Event
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

namespace OCA\Keepiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired when a compromise-recovery migration is aborted with nothing migrated.
 */
class SuiteMigrationAbortedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $oldSuiteId   The suite the vault returns to (still active)
	 * @param string $newSuiteId   The discarded successor suite's id
	 * @param string $migrationId  The aborted migration's id
	 *
	 * @return void
	 */
	public function __construct(
		private string $oldSuiteId,
		private string $newSuiteId,
		private string $migrationId,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The suite the vault returns to.
	 *
	 * @return string
	 */
	public function getOldSuiteId(): string {
		return $this->oldSuiteId;
	}//end getOldSuiteId()

	/**
	 * The discarded successor suite's id.
	 *
	 * @return string
	 */
	public function getNewSuiteId(): string {
		return $this->newSuiteId;
	}//end getNewSuiteId()

	/**
	 * The aborted migration's id.
	 *
	 * @return string
	 */
	public function getMigrationId(): string {
		return $this->migrationId;
	}//end getMigrationId()
}//end class
