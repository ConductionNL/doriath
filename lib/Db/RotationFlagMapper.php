<?php

/**
 * Keepiq Rotation Flag Mapper
 *
 * Query-builder mapper for RotationFlag rows (one-open-flag-per-secret).
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
 * Mapper for the keepiq_rotation_flags table.
 *
 * @template-extends QBMapper<RotationFlag>
 */
class RotationFlagMapper extends QBMapper {
	/**
	 * Constructor for RotationFlagMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_rotation_flags', entityClass: RotationFlag::class);
	}//end __construct()

	/**
	 * Find a flag by its UUID.
	 *
	 * @param string $id The flag UUID
	 *
	 * @return RotationFlag
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): RotationFlag {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * The flag row of a secret (open or resolved; unique per secret).
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @return RotationFlag
	 *
	 * @throws DoesNotExistException When the secret carries no flag
	 */
	public function findBySecret(string $secretId): RotationFlag {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntity(query: $qb);
	}//end findBySecret()

	/**
	 * All open flags whose secrets belong to a user.
	 *
	 * @param string $ownerId The owner user ID
	 *
	 * @return RotationFlag[]
	 */
	public function findOpenForOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.*')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'keepiq_secrets', 's', $qb->expr()->eq('f.secret_id', 's.id'))
			->where($qb->expr()->eq('f.status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->eq('s.owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('s.owner_id', $qb->createNamedParameter($ownerId)));

		return $this->findEntities(query: $qb);
	}//end findOpenForOwner()

	/**
	 * Delete a secret's flag row (delete cascade; idempotent).
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @return void
	 */
	public function deleteBySecret(string $secretId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));
		$qb->executeStatement();
	}//end deleteBySecret()
}//end class
