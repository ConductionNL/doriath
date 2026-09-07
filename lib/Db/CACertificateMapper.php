<?php

/**
 * Keepiq CA Certificate Mapper
 *
 * Database mapper for CA certificate entities.
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for CACertificate entities.
 *
 * @extends QBMapper<CACertificate>
 */
class CACertificateMapper extends QBMapper {
	/**
	 * Constructor for CACertificateMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_ca_certs', entityClass: CACertificate::class);
	}//end __construct()

	/**
	 * Find the active intermediate certificate.
	 *
	 * @return CACertificate
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findActiveIntermediate(): CACertificate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('type', $qb->createNamedParameter('intermediate')))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));

		return $this->findEntity(query: $qb);
	}//end findActiveIntermediate()

	/**
	 * Find the root certificate.
	 *
	 * @return CACertificate
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findRoot(): CACertificate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('type', $qb->createNamedParameter('root')))
			->orderBy('created_at', 'DESC')
			->setMaxResults(1);

		return $this->findEntity(query: $qb);
	}//end findRoot()

	/**
	 * Find certificates expiring within the given number of days.
	 *
	 * @param int $days The number of days to check
	 *
	 * @return CACertificate[]
	 */
	public function findExpiringSoon(int $days): array {
		$qb = $this->db->getQueryBuilder();
		$threshold = new DateTime("+{$days} days");

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($threshold, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->isNull('revoked_at'));

		return $this->findEntities(query: $qb);
	}//end findExpiringSoon()
}//end class
