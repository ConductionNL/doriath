<?php

/**
 * Keepiq Migration Abort Refused Exception
 *
 * Thrown when a compromise-recovery migration cannot be aborted because at
 * least one record has already been committed to the new suite. Aborting then
 * would have to either strand the committed records on a discarded suite or
 * strand the un-migrated ones on the old suite; either loses data, so the
 * migration stays `in_progress` and the caller is pointed at resuming.
 * Controllers map this to an HTTP 409 response and surface the committed count.
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
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

namespace OCA\Keepiq\Exception;

use RuntimeException;

/**
 * Thrown when a migration has moved records and can no longer be aborted.
 */
class MigrationAbortRefusedException extends RuntimeException {
	/**
	 * How many records have already been committed to the new suite.
	 *
	 * @var integer
	 */
	private int $committed = 0;

	/**
	 * Record the committed count to surface to the caller.
	 *
	 * @param integer $committed The number of records already on the new suite
	 *
	 * @return self
	 */
	public function withCommitted(int $committed): self {
		$this->committed = $committed;
		return $this;
	}//end withCommitted()

	/**
	 * The number of records already committed to the new suite.
	 *
	 * @return integer
	 */
	public function getCommitted(): int {
		return $this->committed;
	}//end getCommitted()
}//end class
