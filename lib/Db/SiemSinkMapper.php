<?php

/**
 * Keepiq SIEM Sink Mapper
 *
 * Query-builder mapper for SiemSink rows (siem-audit-export §1.3).
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
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_siem_sinks table.
 *
 * @template-extends QBMapper<SiemSink>
 */
class SiemSinkMapper extends QBMapper {
	/**
	 * Constructor for SiemSinkMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_siem_sinks', entityClass: SiemSink::class);
	}//end __construct()

	/**
	 * Find a sink by its UUID.
	 *
	 * @param string $id The sink UUID
	 *
	 * @return SiemSink
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): SiemSink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * All sinks, newest first.
	 *
	 * @return SiemSink[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * All enabled sinks.
	 *
	 * @return SiemSink[]
	 */
	public function findEnabled(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, 'boolean')));

		return $this->findEntities(query: $qb);
	}//end findEnabled()
}//end class
