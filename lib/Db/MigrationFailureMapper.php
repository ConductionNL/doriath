<?php

/**
 * Keepiq Migration Failure mapper
 *
 * The authoritative record of what a compromise-recovery migration could not
 * carry across, keyed per RECORD.
 *
 * Two numbers are read off this table and they are not the same:
 *
 *   - `countByMigration()` is the acknowledgement threshold. It is a
 *     `COUNT(*)`, deliberately NOT `count()` of a capped `findEntities`,
 *     because a capped list would under-report the loss the user is being
 *     asked to accept and would make the acknowledgement unsatisfiable once
 *     the real number exceeded the cap.
 *   - `findByMigration()` is the DISPLAY list, which may be capped.
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

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Per-record migration-failure persistence.
 *
 * @template-extends QBMapper<MigrationFailure>
 */
class MigrationFailureMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'keepiq_migration_failures',
			entityClass: MigrationFailure::class
		);
	}//end __construct()

	/**
	 * Record a failure for one record, or update the existing one.
	 *
	 * Idempotent by (migration_id, store, record_id): a retry that fails again
	 * REPLACES its message rather than adding a second row. Duplicate rows
	 * would inflate the acknowledgement threshold above the number of records
	 * actually lost, which is one of the ways the previous accounting made
	 * termination unreachable.
	 *
	 * @param string $migrationId The migration
	 * @param string $store The store the record lives in
	 * @param string $recordId The failing record's id
	 * @param string $secretId The owning secret's id
	 * @param string|null $message Why it failed
	 *
	 * @return MigrationFailure The stored failure
	 */
	public function record(
		string $migrationId,
		string $store,
		string $recordId,
		string $secretId,
		?string $message,
	): MigrationFailure {
		$existing = $this->findRecord(migrationId: $migrationId, store: $store, recordId: $recordId);

		if ($existing !== null) {
			$existing->setMessage($message);
			$existing->setSecretId($secretId);
			return $this->update(entity: $existing);
		}

		$failure = new MigrationFailure();
		$failure->setMigrationId($migrationId);
		$failure->setStore($store);
		$failure->setRecordId($recordId);
		$failure->setSecretId($secretId);
		$failure->setMessage($message);
		$failure->setCreatedAt(new DateTime());

		return $this->insert(entity: $failure);
	}//end record()

	/**
	 * One failure by its record identity, or null.
	 *
	 * @param string $migrationId The migration
	 * @param string $store The store
	 * @param string $recordId The record id
	 *
	 * @return MigrationFailure|null
	 */
	public function findRecord(string $migrationId, string $store, string $recordId): ?MigrationFailure {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('migration_id', $qb->createNamedParameter($migrationId)))
			->andWhere($qb->expr()->eq('store', $qb->createNamedParameter($store)))
			->andWhere($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId)))
			->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);

		return ($rows[0] ?? null);
	}//end findRecord()

	/**
	 * Clear the failure for exactly one record.
	 *
	 * Scoped to the record being committed. The previous implementation nulled
	 * a column shared by the secret head and all of its versions and grants,
	 * so committing any one of them erased a sibling's recorded failure.
	 *
	 * @param string $migrationId The migration
	 * @param string $store The store
	 * @param string $recordId The record id
	 *
	 * @return void
	 */
	public function clearRecord(string $migrationId, string $store, string $recordId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('migration_id', $qb->createNamedParameter($migrationId)))
			->andWhere($qb->expr()->eq('store', $qb->createNamedParameter($store)))
			->andWhere($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId)));
		$qb->executeStatement();
	}//end clearRecord()

	/**
	 * How many records this migration has recorded as failed.
	 *
	 * This is the acknowledgement threshold, so it MUST be a COUNT over the
	 * whole set rather than the size of a paged display list.
	 *
	 * @param string $migrationId The migration
	 *
	 * @return integer
	 */
	public function countByMigration(string $migrationId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from($this->getTableName())
			->where($qb->expr()->eq('migration_id', $qb->createNamedParameter($migrationId)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['total'] ?? 0);
	}//end countByMigration()

	/**
	 * The failures for a migration, newest first, for display.
	 *
	 * @param string $migrationId The migration
	 * @param integer $limit Display cap
	 *
	 * @return MigrationFailure[]
	 */
	public function findByMigration(string $migrationId, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('migration_id', $qb->createNamedParameter($migrationId)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, $limit));

		return $this->findEntities(query: $qb);
	}//end findByMigration()

	/**
	 * How many records this migration has failed in one store.
	 *
	 * The per-store "unaccounted" count is derived as
	 * `rows still on the old suite - failures recorded for that store`, which
	 * needs no join through the owning secret. That join is exactly what made
	 * a failed version invisible once its secret head had migrated: the
	 * version was excluded because its SECRET carried an error, while the
	 * secret was not returned because it now sat on the NEW suite.
	 *
	 * Success clears a record's failure, so every remaining failure row
	 * belongs to a record still bound to the old suite — which is what makes
	 * the subtraction sound.
	 *
	 * @param string $migrationId The migration
	 * @param string $store The store to count within
	 *
	 * @return integer
	 */
	public function countByMigrationAndStore(string $migrationId, string $store): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from($this->getTableName())
			->where($qb->expr()->eq('migration_id', $qb->createNamedParameter($migrationId)))
			->andWhere($qb->expr()->eq('store', $qb->createNamedParameter($store)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['total'] ?? 0);
	}//end countByMigrationAndStore()

	/**
	 * Drop every failure belonging to a migration.
	 *
	 * Called when a migration terminates: the accounting is in-flight state,
	 * not history, so it dies with the run that produced it.
	 *
	 * @param string $migrationId The migration
	 *
	 * @return void
	 */
	public function deleteByMigration(string $migrationId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('migration_id', $qb->createNamedParameter($migrationId)));
		$qb->executeStatement();
	}//end deleteByMigration()
}//end class
