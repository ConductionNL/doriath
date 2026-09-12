<?php

/**
 * Keepiq Attachment Mapper
 *
 * Query-builder mapper for Attachment rows.
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
 * Mapper for the keepiq_attachments table.
 *
 * @template-extends QBMapper<Attachment>
 */
class AttachmentMapper extends QBMapper {
	/**
	 * Constructor for AttachmentMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_attachments', entityClass: Attachment::class);
	}//end __construct()

	/**
	 * Find an attachment by its UUID.
	 *
	 * @param string $id The attachment UUID
	 *
	 * @return Attachment
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): Attachment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find all attachments uploaded against a source secret.
	 *
	 * @param string $sourceSecretId The owner's Secret UUID
	 *
	 * @return Attachment[]
	 */
	public function findBySourceSecret(string $sourceSecretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)));

		return $this->findEntities(query: $qb);
	}//end findBySourceSecret()

	/**
	 * Sum the ciphertext bytes of attachments whose source secrets belong
	 * to a user (quota accounting).
	 *
	 * @param string $ownerId The Nextcloud user ID
	 *
	 * @return int
	 */
	public function sumBytesForOwner(string $ownerId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('a.size_bytes'))
			->from($this->getTableName(), 'a')
			->innerJoin('a', 'keepiq_secrets', 's', $qb->expr()->eq('a.source_secret_id', 's.id'))
			->where($qb->expr()->eq('s.owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('s.owner_id', $qb->createNamedParameter($ownerId)));

		$result = $qb->executeQuery();
		$sum = $result->fetchOne();
		$result->closeCursor();

		if ($sum === false || $sum === null) {
			return 0;
		}

		return (int)$sum;
	}//end sumBytesForOwner()
}//end class
