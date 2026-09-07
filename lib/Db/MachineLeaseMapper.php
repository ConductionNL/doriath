<?php

/**
 * Keepiq Machine Lease Mapper
 *
 * Query-builder mapper for MachineLease rows (machine-secret-leases §1.2).
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
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_machine_leases table.
 *
 * @template-extends QBMapper<MachineLease>
 */
class MachineLeaseMapper extends QBMapper {
	/**
	 * Constructor for MachineLeaseMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_machine_leases', entityClass: MachineLease::class);
	}//end __construct()

	/**
	 * Find a lease by its UUID.
	 *
	 * @param string $id The lease UUID
	 *
	 * @return MachineLease
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): MachineLease {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * The live (active, unexpired) lease of an application on a secret,
	 * or null.
	 *
	 * @param string $applicationId The application id
	 * @param string $secretId The secret id
	 * @param DateTime $now The evaluation instant
	 *
	 * @return MachineLease|null
	 */
	public function findLive(string $applicationId, string $secretId, DateTime $now): ?MachineLease {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('application_id', $qb->createNamedParameter($applicationId)))
			->andWhere($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, 'datetime')))
			->orderBy('granted_at', 'DESC')
			->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);
		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end findLive()

	/**
	 * The most recent lease row of an application on a secret regardless
	 * of status, or null (for the block-on-revoke check).
	 *
	 * @param string $applicationId The application id
	 * @param string $secretId The secret id
	 *
	 * @return MachineLease|null
	 */
	public function findLatest(string $applicationId, string $secretId): ?MachineLease {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('application_id', $qb->createNamedParameter($applicationId)))
			->andWhere($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
			->orderBy('granted_at', 'DESC')
			->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);
		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end findLatest()

	/**
	 * All leases of an application, newest first.
	 *
	 * @param string $applicationId The application id
	 * @param int $limit Maximum rows
	 *
	 * @return MachineLease[]
	 */
	public function findByApplication(string $applicationId, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('application_id', $qb->createNamedParameter($applicationId)))
			->orderBy('granted_at', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findByApplication()

	/**
	 * Active leases whose expiry lies in the past (expiry-job input).
	 *
	 * @param DateTime $now The evaluation instant
	 * @param int $limit Maximum rows per batch
	 *
	 * @return MachineLease[]
	 */
	public function findExpiredActive(DateTime $now, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, 'datetime')))
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findExpiredActive()

	/**
	 * Delete an application's lease rows (application-delete cascade).
	 *
	 * @param string $applicationId The application id
	 *
	 * @return void
	 */
	public function deleteByApplication(string $applicationId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('application_id', $qb->createNamedParameter($applicationId)));
		$qb->executeStatement();
	}//end deleteByApplication()
}//end class
