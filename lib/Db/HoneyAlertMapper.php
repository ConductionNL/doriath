<?php

/**
 * Keepiq Honey Alert Mapper
 *
 * Query-builder mapper for HoneyAlert rows (honey-credentials §1.2):
 * owner-scoped and instance-wide listings, the accessor-dedup lookup,
 * and the unacknowledged count for the admin dashboard.
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

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_honey_alerts table.
 *
 * @template-extends QBMapper<HoneyAlert>
 */
class HoneyAlertMapper extends QBMapper {
	/**
	 * Constructor for HoneyAlertMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_honey_alerts', entityClass: HoneyAlert::class);
	}//end __construct()

	/**
	 * One alert by id.
	 *
	 * @param string $id The alert UUID
	 *
	 * @return HoneyAlert
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When missing
	 */
	public function findById(string $id): HoneyAlert {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Alerts of a set of flags, newest first (owner listing).
	 *
	 * @param string[] $flagIds The owner's flag ids
	 * @param int $limit Maximum rows
	 *
	 * @return HoneyAlert[]
	 */
	public function findByFlagIds(array $flagIds, int $limit = 200): array {
		if ($flagIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('honey_flag_id', $qb->createNamedParameter($flagIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('accessed_at', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findByFlagIds()

	/**
	 * All alerts instance-wide, newest first (admin listing).
	 *
	 * @param int $limit Maximum rows
	 *
	 * @return HoneyAlert[]
	 */
	public function findAll(int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('accessed_at', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * The most recent alert of one accessor on one flag+channel (the
	 * dedup / snooze lookup).
	 *
	 * @param string $honeyFlagId The flag UUID
	 * @param string $accessorType The accessor type
	 * @param string|null $accessorId The accessor id (null = anonymous)
	 * @param string $channel The access channel
	 *
	 * @return HoneyAlert|null
	 */
	public function findLatestForAccessor(
		string $honeyFlagId,
		string $accessorType,
		?string $accessorId,
		string $channel,
	): ?HoneyAlert {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('honey_flag_id', $qb->createNamedParameter($honeyFlagId)))
			->andWhere($qb->expr()->eq('accessor_type', $qb->createNamedParameter($accessorType)))
			->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)));

		$accessorPredicate = $qb->expr()->isNull('accessor_id');
		if ($accessorId !== null) {
			$accessorPredicate = $qb->expr()->eq('accessor_id', $qb->createNamedParameter($accessorId));
		}

		$qb->andWhere($accessorPredicate);

		$qb->orderBy('accessed_at', 'DESC')->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);
		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end findLatestForAccessor()

	/**
	 * Count unacknowledged alerts (admin dashboard).
	 *
	 * @return int
	 */
	public function countUnacknowledged(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->isNull('acknowledged_at'));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countUnacknowledged()
}//end class
