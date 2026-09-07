<?php

/**
 * Keepiq Audit Entry Mapper
 *
 * Database mapper for the append-only keepiq_audit_log table
 * (add-secret-audit-trail §1.2). Exposes a single insert path plus scoped
 * read queries (by secret, by actor, admin-filtered with pagination + total
 * count), the retention purge, and account-deletion anonymization. There is
 * deliberately NO generic update-by-id or delete-by-id method: the log is
 * append-only at the application surface, and the only permitted mutations
 * are purgeOlderThan and anonymizeUser.
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for AuditEntry rows.
 *
 * @extends QBMapper<AuditEntry>
 */
class AuditEntryMapper extends QBMapper {
	/**
	 * Marker substituted for a deleted user's id in anonymized entries.
	 *
	 * @var string
	 */
	public const DELETED_ACCOUNT_MARKER = 'deleted-account';

	/**
	 * Constructor for AuditEntryMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_audit_log', entityClass: AuditEntry::class);
	}//end __construct()

	/**
	 * Find entries for a single object (e.g. one secret), newest first.
	 *
	 * @param string $objectType The object type
	 * @param string $objectId The object ID
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return AuditEntry[]
	 */
	public function findByObject(string $objectType, string $objectId, int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter($objectType)))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($objectId)))
			->orderBy('occurred_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities(query: $qb);
	}//end findByObject()

	/**
	 * Find entries actored by a given user, newest first.
	 *
	 * @param string $actorId The actor's user ID
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return AuditEntry[]
	 */
	public function findByActor(string $actorId, int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)))
			->orderBy('occurred_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities(query: $qb);
	}//end findByActor()

	/**
	 * Find the most recent distinct secret-read entries for a user.
	 *
	 * Powers the dashboard "Recently accessed secrets" widget (design D4) —
	 * the access log is not a separate table.
	 *
	 * @param string $actorId The actor's user ID
	 * @param int $limit Maximum rows
	 *
	 * @return AuditEntry[]
	 */
	public function findRecentReadsByActor(string $actorId, int $limit = 5): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)))
			->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter('secret.read')))
			->orderBy('occurred_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findRecentReadsByActor()

	/**
	 * Admin instance-wide filtered query, newest first, paginated.
	 *
	 * @param array<string,mixed> $filters Optional filters: eventType, actor,
	 *                                     objectType, objectId, from, to
	 *                                     (DateTime or ISO string)
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return AuditEntry[]
	 */
	public function findFiltered(array $filters, int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->applyFilters(qb: $qb, filters: $filters);
		$qb->orderBy('occurred_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities(query: $qb);
	}//end findFiltered()

	/**
	 * Total count for an admin filtered query (for pagination).
	 *
	 * @param array<string,mixed> $filters See findFiltered
	 *
	 * @return int
	 */
	public function countFiltered(array $filters): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName());
		$this->applyFilters(qb: $qb, filters: $filters);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countFiltered()

	/**
	 * Apply admin filter clauses to a query builder.
	 *
	 * @param IQueryBuilder $qb The query builder
	 * @param array<string,mixed> $filters The filters
	 *
	 * @return void
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters): void {
		if (empty($filters['eventType']) === false) {
			$qb->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter((string)$filters['eventType'])));
		}

		if (empty($filters['actor']) === false) {
			$qb->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter((string)$filters['actor'])));
		}

		if (empty($filters['objectType']) === false) {
			$qb->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter((string)$filters['objectType'])));
		}

		if (empty($filters['objectId']) === false) {
			$qb->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$filters['objectId'])));
		}

		if (empty($filters['from']) === false) {
			$qb->andWhere(
				$qb->expr()->gte(
					'occurred_at',
					$qb->createNamedParameter($this->normaliseDate(value: $filters['from']), IQueryBuilder::PARAM_DATE)
				)
			);
		}

		if (empty($filters['to']) === false) {
			$qb->andWhere(
				$qb->expr()->lte(
					'occurred_at',
					$qb->createNamedParameter($this->normaliseDate(value: $filters['to']), IQueryBuilder::PARAM_DATE)
				)
			);
		}
	}//end applyFilters()

	/**
	 * Normalise a filter date value to a DateTime.
	 *
	 * @param mixed $value DateTime or ISO-8601 string
	 *
	 * @return DateTime
	 */
	private function normaliseDate($value): DateTime {
		if ($value instanceof DateTime) {
			return $value;
		}

		return new DateTime((string)$value);
	}//end normaliseDate()

	/**
	 * Delete entries older than the given cutoff, in bounded batches.
	 *
	 * One of only two permitted mutations of the log (the other is
	 * anonymizeUser). Returns the number of rows deleted in this batch so the
	 * caller can loop until the batch comes back smaller than the limit.
	 *
	 * @param DateTime $cutoff Entries strictly older than this are removed
	 * @param int $batchSize Maximum rows to delete in one statement
	 *
	 * @return int Number of rows deleted
	 */
	public function purgeOlderThan(DateTime $cutoff, int $batchSize = 1000): int {
		// Collect a bounded batch of ids first, then delete by id — keeps the
		// delete statement portable across DB drivers (no LIMIT on DELETE).
		$select = $this->db->getQueryBuilder();
		$select->select('id')
			->from($this->getTableName())
			->where(
				$select->expr()->lt(
					'occurred_at',
					$select->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)
				)
			)
			->setMaxResults($batchSize);

		$result = $select->executeQuery();
		$ids = array_map(static fn ($row): int => (int)$row, $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		if (count($ids) === 0) {
			return 0;
		}

		$delete = $this->db->getQueryBuilder();
		$delete->delete($this->getTableName())
			->where(
				$delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY))
			);

		return $delete->executeStatement();
	}//end purgeOlderThan()

	/**
	 * Anonymize a deleted user out of the log without deleting entries.
	 *
	 * Replaces the user as actor_id by the deleted-account marker. Whitelisted
	 * metadata fields referencing the user (handled by AuditService) are scrubbed
	 * separately; this method handles the actor_id column. Entries are retained
	 * so other users' accountability records survive (design D6).
	 *
	 * @param string $userId The deleted user's ID
	 *
	 * @return int Number of rows whose actor_id was updated
	 */
	public function anonymizeActor(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('actor_id', $qb->createNamedParameter(self::DELETED_ACCOUNT_MARKER))
			->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($userId)));

		return $qb->executeStatement();
	}//end anonymizeActor()

	/**
	 * Update the metadata JSON of a single entry (anonymization only).
	 *
	 * Used solely by AuditService::anonymizeUser to scrub user-referencing
	 * whitelisted metadata fields. Not exposed as a general update path.
	 *
	 * @param int $id The entry ID
	 * @param string|null $metadata The new metadata JSON
	 *
	 * @return void
	 */
	public function rewriteMetadata(int $id, ?string $metadata): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('metadata', $qb->createNamedParameter($metadata))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}//end rewriteMetadata()

	/**
	 * Find entries whose metadata references a given user id in any value.
	 *
	 * Coarse LIKE prefilter used by anonymizeUser to find candidate rows whose
	 * whitelisted metadata may name the departed user; the service confirms and
	 * rewrites only the user-referencing keys.
	 *
	 * @param string $userId The user ID to search for
	 *
	 * @return AuditEntry[]
	 */
	public function findMetadataReferencing(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->iLike(
					'metadata',
					$qb->createNamedParameter('%' . $this->db->escapeLikeParameter($userId) . '%')
				)
			);

		return $this->findEntities(query: $qb);
	}//end findMetadataReferencing()
}//end class
