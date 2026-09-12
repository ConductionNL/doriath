<?php

/**
 * Keepiq Honey Flag Mapper
 *
 * Query-builder mapper for HoneyFlag rows (honey-credentials §1.2).
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
 * Mapper for the keepiq_honey_flags table.
 *
 * @template-extends QBMapper<HoneyFlag>
 */
class HoneyFlagMapper extends QBMapper {
	/**
	 * Constructor for HoneyFlagMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_honey_flags', entityClass: HoneyFlag::class);
	}//end __construct()

	/**
	 * The flag of a secret (the tripwire's hot-path lookup).
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @return HoneyFlag
	 *
	 * @throws DoesNotExistException When the secret is not flagged
	 */
	public function findBySecretId(string $secretId): HoneyFlag {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntity(query: $qb);
	}//end findBySecretId()

	/**
	 * All flags of an owner.
	 *
	 * @param string $ownerId The NC user id
	 *
	 * @return HoneyFlag[]
	 */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

		return $this->findEntities(query: $qb);
	}//end findByOwner()
}//end class
