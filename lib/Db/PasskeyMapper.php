<?php

/**
 * Keepiq Passkey Mapper
 *
 * Query-builder mapper for PasskeyCredential rows
 * (passkey-vault-login §1.2). Every read is owner-scoped by the caller.
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
 * Mapper for the keepiq_passkey_credentials table.
 *
 * @template-extends QBMapper<PasskeyCredential>
 */
class PasskeyMapper extends QBMapper {
	/**
	 * Constructor for PasskeyMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_passkey_credentials', entityClass: PasskeyCredential::class);
	}//end __construct()

	/**
	 * One credential by id.
	 *
	 * @param string $id The credential UUID
	 *
	 * @return PasskeyCredential
	 *
	 * @throws DoesNotExistException When missing
	 */
	public function findById(string $id): PasskeyCredential {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * All of an owner's credentials, newest first.
	 *
	 * @param string $ownerId The NC user id
	 *
	 * @return PasskeyCredential[]
	 */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByOwner()

	/**
	 * An owner's active credentials (unlock-options source).
	 *
	 * @param string $ownerId The NC user id
	 *
	 * @return PasskeyCredential[]
	 */
	public function findActiveByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));

		return $this->findEntities(query: $qb);
	}//end findActiveByOwner()

	/**
	 * The credential matching a base64url credential id for an owner, or
	 * null (duplicate-enrollment guard + unlock lookup).
	 *
	 * @param string $ownerId The NC user id
	 * @param string $credentialId The base64url WebAuthn credential id
	 *
	 * @return PasskeyCredential|null
	 */
	public function findByCredentialId(string $ownerId, string $credentialId): ?PasskeyCredential {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('credential_id', $qb->createNamedParameter($credentialId)));

		$rows = $this->findEntities(query: $qb);
		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end findByCredentialId()

	/**
	 * Delete every credential of an owner (compromise-recovery rotation
	 * cascade, §D4). Idempotent.
	 *
	 * @param string $ownerId The NC user id
	 *
	 * @return void
	 */
	public function deleteByOwner(string $ownerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));
		$qb->executeStatement();
	}//end deleteByOwner()

	/**
	 * Mark an owner's active envelopes stale (routine master-password
	 * change, §D4).
	 *
	 * @param string $ownerId The NC user id
	 *
	 * @return void
	 */
	public function markOwnerStale(string $ownerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('stale'))
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));
		$qb->executeStatement();
	}//end markOwnerStale()
}//end class
