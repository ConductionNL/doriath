<?php

/**
 * Keepiq Expiry Policy Mapper
 *
 * Query-builder mapper for ExpiryPolicy rows.
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
 * Mapper for the keepiq_expiry_policies table.
 *
 * @template-extends QBMapper<ExpiryPolicy>
 */
class ExpiryPolicyMapper extends QBMapper {
	/**
	 * Constructor for ExpiryPolicyMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_expiry_policies', entityClass: ExpiryPolicy::class);
	}//end __construct()

	/**
	 * Find a policy by its UUID.
	 *
	 * @param string $id The policy UUID
	 *
	 * @return ExpiryPolicy
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): ExpiryPolicy {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * All policies of one owner (null owner = instance-wide policies).
	 *
	 * @param string|null $ownerId The owner (null = admin default rows)
	 *
	 * @return ExpiryPolicy[]
	 */
	public function findByOwner(?string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		$ownerPredicate = $qb->expr()->isNull('owner_id');
		if ($ownerId !== null) {
			$ownerPredicate = $qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId));
		}

		$qb->where($ownerPredicate);

		return $this->findEntities(query: $qb);
	}//end findByOwner()

	/**
	 * The policies applicable to a secret: the owner's rows plus the
	 * instance-wide rows, filtered by scope match in the service.
	 *
	 * @param string $ownerId The secret owner's user ID
	 *
	 * @return ExpiryPolicy[]
	 */
	public function findApplicable(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)),
					$qb->expr()->isNull('owner_id')
				)
			);

		return $this->findEntities(query: $qb);
	}//end findApplicable()
}//end class
