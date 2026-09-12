<?php

/**
 * Keepiq Certificate Metadata Mapper
 *
 * Query-builder mapper for CertificateMetadata rows
 * (certificate-lifecycle §1.2).
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
 * Mapper for the keepiq_certificate_metadata table.
 *
 * @template-extends QBMapper<CertificateMetadata>
 */
class CertificateMetadataMapper extends QBMapper {
	/**
	 * Constructor for CertificateMetadataMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_certificate_metadata', entityClass: CertificateMetadata::class);
	}//end __construct()

	/**
	 * Find the metadata row of a secret.
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @return CertificateMetadata
	 *
	 * @throws DoesNotExistException When no row exists
	 */
	public function findBySecretId(string $secretId): CertificateMetadata {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntity(query: $qb);
	}//end findBySecretId()

	/**
	 * All metadata rows of an owner, keyed by secret id.
	 *
	 * @param string $ownerId The NC user id
	 *
	 * @return array<string,CertificateMetadata>
	 */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

		$bySecret = [];
		foreach ($this->findEntities(query: $qb) as $row) {
			$bySecret[$row->getSecretId()] = $row;
		}

		return $bySecret;
	}//end findByOwner()

	/**
	 * Delete the metadata row of a secret, if any (secret-delete
	 * cascade).
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @return void
	 */
	public function deleteBySecretId(string $secretId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));
		$qb->executeStatement();
	}//end deleteBySecretId()
}//end class
