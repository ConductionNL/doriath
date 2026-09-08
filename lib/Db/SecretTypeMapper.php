<?php

/**
 * Keepiq Secret Type Mapper
 *
 * Database mapper for secret type entities.
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
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for SecretType entities.
 *
 * @extends QBMapper<SecretType>
 */
class SecretTypeMapper extends QBMapper {
	/**
	 * Constructor for SecretTypeMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_secret_types', entityClass: SecretType::class);
	}//end __construct()

	/**
	 * Find a secret type by its UUID.
	 *
	 * @param string $id The type ID
	 *
	 * @return SecretType
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): SecretType {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find a secret type by its unique name (names are globally unique).
	 *
	 * @param string $name The type name
	 *
	 * @return SecretType
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByName(string $name): SecretType {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));

		return $this->findEntity(query: $qb);
	}//end findByName()

	/**
	 * Find all system-scoped secret types.
	 *
	 * @return SecretType[]
	 */
	public function findSystemTypes(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('scope', $qb->createNamedParameter('system')));

		return $this->findEntities(query: $qb);
	}//end findSystemTypes()

	/**
	 * Find all secret types available to a given user: system, global, and
	 * the user's own user-scoped types.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return SecretType[]
	 */
	public function findAvailableForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('scope', $qb->createNamedParameter('system')),
					$qb->expr()->eq('scope', $qb->createNamedParameter('global')),
					$qb->expr()->andX(
						$qb->expr()->eq('scope', $qb->createNamedParameter('user')),
						$qb->expr()->eq('owner_id', $qb->createNamedParameter($userId))
					)
				)
			)
			->orderBy('label', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findAvailableForUser()

	/**
	 * Find all secret types of a given scope.
	 *
	 * @param string $scope The scope (system, user, global)
	 *
	 * @return SecretType[]
	 */
	public function findByScope(string $scope): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)));

		return $this->findEntities(query: $qb);
	}//end findByScope()

	/**
	 * Count the number of secret types with a given name (used for the
	 * global-uniqueness constraint).
	 *
	 * @param string $name The type name
	 *
	 * @return int
	 */
	public function countByName(string $name): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countByName()
}//end class
