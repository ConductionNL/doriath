<?php

/**
 * Keepiq SIEM Queue Item Mapper
 *
 * Query-builder mapper for SiemQueueItem rows (siem-audit-export §1.3):
 * bounded per-sink queue with due-fetch and cap counting.
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
 * Mapper for the keepiq_siem_queue table.
 *
 * @template-extends QBMapper<SiemQueueItem>
 */
class SiemQueueItemMapper extends QBMapper {
	/**
	 * Constructor for SiemQueueItemMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_siem_queue', entityClass: SiemQueueItem::class);
	}//end __construct()

	/**
	 * Count a sink's undelivered (pending) rows.
	 *
	 * @param string $sinkId The sink UUID
	 *
	 * @return int
	 */
	public function countPending(string $sinkId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('sink_id', $qb->createNamedParameter($sinkId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countPending()

	/**
	 * The oldest pending row of a sink, or null (drop-oldest input).
	 *
	 * @param string $sinkId The sink UUID
	 *
	 * @return SiemQueueItem|null
	 */
	public function oldestPending(string $sinkId): ?SiemQueueItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('sink_id', $qb->createNamedParameter($sinkId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->orderBy('enqueued_at', 'ASC')
			->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);
		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end oldestPending()

	/**
	 * Due pending rows of a sink (next_attempt_at unset or elapsed),
	 * oldest first, in a bounded batch.
	 *
	 * @param string $sinkId The sink UUID
	 * @param DateTime $now The evaluation instant
	 * @param int $limit Batch size
	 *
	 * @return SiemQueueItem[]
	 */
	public function findDue(string $sinkId, DateTime $now, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('sink_id', $qb->createNamedParameter($sinkId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('next_attempt_at'),
					$qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now, 'datetime'))
				)
			)
			->orderBy('enqueued_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findDue()

	/**
	 * Count a sink's dead-lettered rows.
	 *
	 * @param string $sinkId The sink UUID
	 *
	 * @return int
	 */
	public function countDead(string $sinkId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('sink_id', $qb->createNamedParameter($sinkId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('dead')));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countDead()

	/**
	 * Delete a sink's queue rows (sink-delete cascade).
	 *
	 * @param string $sinkId The sink UUID
	 *
	 * @return void
	 */
	public function deleteBySink(string $sinkId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('sink_id', $qb->createNamedParameter($sinkId)));
		$qb->executeStatement();
	}//end deleteBySink()
}//end class
