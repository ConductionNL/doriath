<?php

/**
 * Keepiq Compliance Report Mapper
 *
 * Query-builder mapper for ComplianceReport rows (compliance-reporting
 * §1.2). Append-only: the mapper exposes no update path.
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
 * Mapper for the keepiq_compliance_reports table.
 *
 * @template-extends QBMapper<ComplianceReport>
 */
class ComplianceReportMapper extends QBMapper {
	/**
	 * Constructor for ComplianceReportMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_compliance_reports', entityClass: ComplianceReport::class);
	}//end __construct()

	/**
	 * Find a report by its UUID.
	 *
	 * @param string $id The report UUID
	 *
	 * @return ComplianceReport
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): ComplianceReport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * All reports, newest first.
	 *
	 * @param int $limit Maximum rows
	 *
	 * @return ComplianceReport[]
	 */
	public function findAll(int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('generated_at', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findAll()
}//end class
