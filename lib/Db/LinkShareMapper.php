<?php

/**
 * Keepiq Link Share Mapper
 *
 * Database mapper for link share entities.
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
 * Mapper for LinkShare entities.
 *
 * @extends QBMapper<LinkShare>
 */
class LinkShareMapper extends QBMapper {
	/**
	 * Constructor for LinkShareMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_link_shares', entityClass: LinkShare::class);
	}//end __construct()

	/**
	 * Find a link share by its UUID.
	 *
	 * @param string $id The link share ID
	 *
	 * @return LinkShare
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): LinkShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find a link share by its public access token.
	 *
	 * @param string $token The access token
	 *
	 * @return LinkShare
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByToken(string $token): LinkShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		return $this->findEntity(query: $qb);
	}//end findByToken()

	/**
	 * Find all link shares for a given secret.
	 *
	 * @param string $secretId The secret ID
	 *
	 * @return LinkShare[]
	 */
	public function findBySecretId(string $secretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntities(query: $qb);
	}//end findBySecretId()

	/**
	 * Find all link shares created by a given user.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return LinkShare[]
	 */
	public function findByCreatedBy(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));

		return $this->findEntities(query: $qb);
	}//end findByCreatedBy()

	/**
	 * Delete all link shares for a given secret (cascade on secret deletion).
	 *
	 * @param string $secretId The secret ID
	 *
	 * @return void
	 */
	public function deleteBySecretId(string $secretId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		$qb->executeStatement();
	}//end deleteBySecretId()

	/**
	 * Delete all link shares created by a given user (cascade on compromise recovery).
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return void
	 */
	public function deleteByUserId(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}//end deleteByUserId()

	/**
	 * Atomically increment the usage count for a token, but only while
	 * usage_count is still below usage_limit. Returns the number of rows
	 * affected (1 on success, 0 if the limit was already reached or the
	 * token does not exist). This guards against the two-tab race where
	 * two concurrent confirms could otherwise exceed the limit.
	 *
	 * @param string $token The access token
	 *
	 * @return int The number of affected rows
	 */
	public function incrementUsageIfBelowLimit(string $token): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('usage_count', $qb->createFunction('usage_count + 1'))
			->set('failed_attempts', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->lt('usage_count', 'usage_limit'));

		return $qb->executeStatement();
	}//end incrementUsageIfBelowLimit()
}//end class
