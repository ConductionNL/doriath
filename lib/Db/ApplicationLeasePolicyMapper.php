<?php

/**
 * Keepiq Application Lease Policy Mapper
 *
 * Query-builder mapper for per-application lease-policy overrides
 * (machine-secret-leases §1.2). The table is keyed by application_id
 * (no surrogate id), so writes go through explicit upsert/delete here
 * rather than the QBMapper entity path.
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
 * Mapper for the keepiq_app_lease_policies table.
 *
 * @template-extends QBMapper<ApplicationLeasePolicy>
 */
class ApplicationLeasePolicyMapper extends QBMapper {
	/**
	 * Constructor for ApplicationLeasePolicyMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_app_lease_policies', entityClass: ApplicationLeasePolicy::class);
	}//end __construct()

	/**
	 * The override row of an application.
	 *
	 * @param string $applicationId The application id
	 *
	 * @return ApplicationLeasePolicy
	 *
	 * @throws DoesNotExistException When the application has no override
	 */
	public function findByApplication(string $applicationId): ApplicationLeasePolicy {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('application_id', $qb->createNamedParameter($applicationId)));

		return $this->findEntity(query: $qb);
	}//end findByApplication()

	/**
	 * Create or replace an application's override row.
	 *
	 * @param string $applicationId The application id
	 * @param int|null $defaultTtlSeconds Default TTL override (null = inherit)
	 * @param int|null $maxTtlSeconds Max TTL override (null = inherit)
	 * @param bool|null $renewable Renewability override (null = inherit)
	 *
	 * @return void
	 */
	public function upsert(string $applicationId, ?int $defaultTtlSeconds, ?int $maxTtlSeconds, ?bool $renewable): void {
		$this->deleteByApplication(applicationId: $applicationId);

		$renewableInt = null;
		if ($renewable !== null) {
			$renewableInt = 0;
			if ($renewable === true) {
				$renewableInt = 1;
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values(
				[
					'application_id' => $qb->createNamedParameter($applicationId),
					'default_ttl_seconds' => $qb->createNamedParameter($defaultTtlSeconds, \PDO::PARAM_INT),
					'max_ttl_seconds' => $qb->createNamedParameter($maxTtlSeconds, \PDO::PARAM_INT),
					'renewable' => $qb->createNamedParameter($renewableInt, \PDO::PARAM_INT),
				]
			);
		$qb->executeStatement();
	}//end upsert()

	/**
	 * Delete an application's override row (idempotent).
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
