<?php

/**
 * Keepiq Share Target Mapper
 *
 * Database mapper for share target entities.
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
 * Mapper for ShareTarget entities.
 *
 * @extends QBMapper<ShareTarget>
 */
class ShareTargetMapper extends QBMapper {
	/**
	 * Constructor for ShareTargetMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_share_targets', entityClass: ShareTarget::class);
	}//end __construct()

	/**
	 * Find a share target by its UUID.
	 *
	 * @param string $id The share target ID
	 *
	 * @return ShareTarget
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): ShareTarget {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find all share targets for a given source secret.
	 *
	 * @param string $sourceSecretId The owner's source secret ID
	 *
	 * @return ShareTarget[]
	 */
	public function findBySourceSecret(string $sourceSecretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)));

		return $this->findEntities(query: $qb);
	}//end findBySourceSecret()

	/**
	 * Find all share targets for a given recipient user.
	 *
	 * @param string $targetUserId The recipient Nextcloud user ID
	 *
	 * @return ShareTarget[]
	 */
	public function findByTargetUser(string $targetUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('target_user_id', $qb->createNamedParameter($targetUserId)));

		return $this->findEntities(query: $qb);
	}//end findByTargetUser()

	/**
	 * Delete all share targets for a given source secret (cascade).
	 *
	 * @param string $sourceSecretId The source secret ID
	 *
	 * @return void
	 */
	public function deleteBySourceSecret(string $sourceSecretId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)));

		$qb->executeStatement();
	}//end deleteBySourceSecret()

	/**
	 * Find the share target identifying a (source secret, recipient user) pair.
	 *
	 * Used by the authorization path before creating a new share to enforce
	 * the "one share per recipient per source secret" invariant.
	 *
	 * @param string $sourceSecretId The owner's source secret ID
	 * @param string $targetUserId The recipient Nextcloud user ID
	 *
	 * @return ShareTarget
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#2.2
	 */
	public function findBySourceSecretAndTargetUser(string $sourceSecretId, string $targetUserId): ShareTarget {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)))
			->andWhere($qb->expr()->eq('target_user_id', $qb->createNamedParameter($targetUserId)));

		return $this->findEntity(query: $qb);
	}//end findBySourceSecretAndTargetUser()

	/**
	 * Delete every share target where the recipient is the given user.
	 *
	 * Invoked from the EncryptionSuite-revocation listener and the
	 * group-member-leave listener so a departing recipient's encrypted
	 * copies disappear in a single statement.
	 *
	 * @param string $targetUserId The recipient Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#2.2
	 */
	public function deleteByTargetUser(string $targetUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('target_user_id', $qb->createNamedParameter($targetUserId)));

		$qb->executeStatement();
	}//end deleteByTargetUser()

	/**
	 * Reverse-lookup a ShareTarget by the recipient Secret copy ID.
	 *
	 * Used by the SuiteCompromiseListener to walk from a flagged
	 * recipient copy back to the source secret (and thus the owner who
	 * needs the compromise notification).
	 *
	 * @param string $recipientSecretId The recipient's encrypted Secret copy ID
	 *
	 * @return ShareTarget
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#8.4
	 */
	public function findByRecipientSecret(string $recipientSecretId): ShareTarget {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($recipientSecretId)));

		return $this->findEntity(query: $qb);
	}//end findByRecipientSecret()
}//end class
