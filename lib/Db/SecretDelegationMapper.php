<?php

/**
 * Keepiq Secret Delegation Mapper
 *
 * Database mapper for secret delegation entities.
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
 * Mapper for SecretDelegation entities.
 *
 * @extends QBMapper<SecretDelegation>
 */
class SecretDelegationMapper extends QBMapper {
	/**
	 * Constructor for SecretDelegationMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_secret_delegations', entityClass: SecretDelegation::class);
	}//end __construct()

	/**
	 * Find a delegation by its UUID.
	 *
	 * @param string $id The delegation ID
	 *
	 * @return SecretDelegation
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): SecretDelegation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find all delegations for a given Secret.
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return SecretDelegation[]
	 */
	public function findBySecret(string $secretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntities(query: $qb);
	}//end findBySecret()

	/**
	 * Find the active delegation (if any) for a (secret, delegate-to) pair.
	 *
	 * Used by share/revoke authorization checks: callers ask "is this user
	 * the active delegate for this secret right now?".
	 *
	 * @param string $secretId The Secret ID
	 * @param string $userId The candidate delegate-to user
	 *
	 * @return SecretDelegation
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findActiveBySecretAndUser(string $secretId, string $userId): SecretDelegation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
			->andWhere($qb->expr()->eq('delegated_to', $qb->createNamedParameter($userId)));

		return $this->findEntity(query: $qb);
	}//end findActiveBySecretAndUser()

	/**
	 * Find all delegations where the given user is the original owner.
	 *
	 * @param string $originalOwnerId The original owner user ID
	 *
	 * @return SecretDelegation[]
	 */
	public function findByOriginalOwner(string $originalOwnerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('original_owner_id', $qb->createNamedParameter($originalOwnerId)));

		return $this->findEntities(query: $qb);
	}//end findByOriginalOwner()

	/**
	 * Find all TEMPORARY (is_permanent=false) delegations for the original owner.
	 *
	 * @param string $originalOwnerId The original owner user ID
	 *
	 * @return SecretDelegation[]
	 */
	public function findTemporaryByOriginalOwner(string $originalOwnerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('original_owner_id', $qb->createNamedParameter($originalOwnerId)))
			->andWhere($qb->expr()->eq('is_permanent', $qb->createNamedParameter(false, \PDO::PARAM_BOOL)));

		return $this->findEntities(query: $qb);
	}//end findTemporaryByOriginalOwner()

	/**
	 * Cascade-delete all delegations for a given Secret — used when the
	 * source Secret itself is deleted.
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return void
	 */
	public function deleteBySecret(string $secretId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		$qb->executeStatement();
	}//end deleteBySecret()

	/**
	 * Promote all TEMPORARY delegations for the given original owner to
	 * permanent and stamp the made_permanent_at timestamp. Used by the
	 * EncryptionSuiteRevoked listener to cement delegations when the
	 * original owner loses access for good.
	 *
	 * @param string $originalOwnerId The original owner user ID
	 *
	 * @return int Rows affected.
	 */
	public function makePermanentByOriginalOwner(string $originalOwnerId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('is_permanent', $qb->createNamedParameter(true, \PDO::PARAM_BOOL))
			->set('made_permanent_at', $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('original_owner_id', $qb->createNamedParameter($originalOwnerId)))
			->andWhere($qb->expr()->eq('is_permanent', $qb->createNamedParameter(false, \PDO::PARAM_BOOL)));

		return $qb->executeStatement();
	}//end makePermanentByOriginalOwner()
}//end class
