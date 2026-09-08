<?php

/**
 * Keepiq Suite Migration Mapper
 *
 * Database mapper for suite migration entities.
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for SuiteMigration entities.
 *
 * @extends QBMapper<SuiteMigration>
 */
class SuiteMigrationMapper extends QBMapper {
	/**
	 * Constructor for SuiteMigrationMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_suite_migr', entityClass: SuiteMigration::class);
	}//end __construct()

	/**
	 * Find a suite migration by its ID.
	 *
	 * @param string $id The migration ID
	 *
	 * @return SuiteMigration
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): SuiteMigration {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find an in-progress migration for a given owner by checking suites.
	 *
	 * @param string $oldSuiteId The old suite ID to find migration for
	 *
	 * @return SuiteMigration
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findInProgressByOldSuiteId(string $oldSuiteId): SuiteMigration {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('old_suite_id', $qb->createNamedParameter($oldSuiteId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('in_progress')));

		return $this->findEntity(query: $qb);
	}//end findInProgressByOldSuiteId()

	/**
	 * Find all migrations related to a suite ID.
	 *
	 * @param string $suiteId The suite ID to search for
	 *
	 * @return SuiteMigration[]
	 */
	public function findBySuiteId(string $suiteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('old_suite_id', $qb->createNamedParameter($suiteId)),
					$qb->expr()->eq('new_suite_id', $qb->createNamedParameter($suiteId))
				)
			);

		return $this->findEntities(query: $qb);
	}//end findBySuiteId()

	/**
	 * Check if any in-progress migration exists for the given suite IDs.
	 *
	 * @param string $oldSuiteId The old suite ID to check
	 *
	 * @return bool
	 */
	public function hasInProgress(string $oldSuiteId): bool {
		try {
			$this->findInProgressByOldSuiteId(oldSuiteId: $oldSuiteId);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}//end hasInProgress()

	/**
	 * Delete every migration record referencing any of the given suite IDs.
	 *
	 * Used by the account-deletion cascade after the user's suites are listed
	 * but before they are removed. Idempotent.
	 *
	 * @param string[] $suiteIds The suite IDs (old or new) to purge
	 *
	 * @return int The number of rows deleted
	 *
	 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
	 */
	public function deleteBySuiteIds(array $suiteIds): int {
		if ($suiteIds === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->in(
						'old_suite_id',
						$qb->createNamedParameter($suiteIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
					),
					$qb->expr()->in(
						'new_suite_id',
						$qb->createNamedParameter($suiteIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
					)
				)
			);

		return $qb->executeStatement();
	}//end deleteBySuiteIds()
}//end class
