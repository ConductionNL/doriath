<?php

/**
 * Keepiq Secret Version Mapper
 *
 * Query-builder mapper for SecretVersion rows: next-version-number,
 * newest-first listing, count/age pruning, and the delete cascade.
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
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_secret_versions table.
 *
 * @template-extends QBMapper<SecretVersion>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Each method is a single,
 *   focused query the service layer composes (find/prune/count/migrate);
 *   splitting the mapper would scatter one table's access across several
 *   classes for no benefit. Mirrors the same suppression on SecretMapper.
 */
class SecretVersionMapper extends QBMapper {
	/**
	 * Constructor for SecretVersionMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_secret_versions', entityClass: SecretVersion::class);
	}//end __construct()

	/**
	 * Find a version by its UUID.
	 *
	 * @param string $id The version UUID
	 *
	 * @return SecretVersion
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): SecretVersion {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * List a secret's versions, newest first.
	 *
	 * @param string $secretId The secret UUID
	 * @param int $limit Maximum rows
	 *
	 * @return SecretVersion[]
	 */
	public function findBySecret(string $secretId, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
			->orderBy('version_number', 'DESC')
			->setMaxResults(max(1, $limit));

		return $this->findEntities(query: $qb);
	}//end findBySecret()

	/**
	 * The next version number for a secret (1-based, monotonic).
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @return int
	 */
	public function nextVersionNumber(string $secretId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('version_number'))
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		if ($max === false || $max === null) {
			return 1;
		}

		return ((int)$max) + 1;
	}//end nextVersionNumber()

	/**
	 * Prune a secret's oldest versions beyond a retention count. The live
	 * head is a `keepiq_secrets` row and is structurally untouchable here.
	 *
	 * @param string $secretId The secret UUID
	 * @param int $keep Versions to keep (newest)
	 *
	 * @return int Rows pruned
	 */
	public function pruneByCount(string $secretId, int $keep): int {
		$victims = [];
		foreach ($this->findBySecret(secretId: $secretId, limit: 100000) as $index => $version) {
			if ($index >= $keep) {
				$victims[] = $version;
			}
		}

		foreach ($victims as $version) {
			$this->delete(entity: $version);
		}

		return count($victims);
	}//end pruneByCount()

	/**
	 * Prune versions older than a cutoff, in a bounded batch across all
	 * secrets.
	 *
	 * @param DateTime $cutoff The age cutoff
	 * @param int $limit Maximum rows to prune in one call
	 *
	 * @return int Rows pruned
	 */
	public function pruneOlderThan(DateTime $cutoff, int $limit = 500): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')))
			->setMaxResults(max(1, $limit));

		$pruned = 0;
		foreach ($this->findEntities(query: $qb) as $version) {
			$this->delete(entity: $version);
			++$pruned;
		}

		return $pruned;
	}//end pruneOlderThan()

	/**
	 * Delete every version of a secret (delete cascade; idempotent).
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

	/**
	 * The distinct secret ids that currently have versions (prune sweep).
	 *
	 * @param int $limit Maximum ids
	 *
	 * @return string[]
	 */
	public function findSecretIdsWithVersions(int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('secret_id')
			->from($this->getTableName())
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$ids = array_column($result->fetchAll(), 'secret_id');
		$result->closeCursor();

		return $ids;
	}//end findSecretIdsWithVersions()

	/**
	 * A secret's versions still bound to a suite, newest first.
	 *
	 * The head lives in `keepiq_secrets` and is migrated separately; this
	 * returns only the snapshot rows, which carry their own
	 * `encryption_suite_id` and so migrate independently of their head.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $encryptionSuiteId The suite ID
	 *
	 * @return SecretVersion[]
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function findBySecretAndSuite(string $secretId, string $encryptionSuiteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
			->andWhere($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->orderBy('version_number', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findBySecretAndSuite()

	/**
	 * Count an owner's version rows still bound to a suite.
	 *
	 * A version has no owner column of its own — ownership is resolved
	 * through the secret it snapshots, which is why this joins rather than
	 * filtering. Note the join is on the secret's identity only, NOT on the
	 * secret's suite: a head that has already migrated still owns versions
	 * that have not.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return int
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function countBySuiteForOwner(
		string $encryptionSuiteId,
		string $ownerType,
		string $ownerId,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName(), 'v')
			->innerJoin('v', 'keepiq_secrets', 's', $qb->expr()->eq('v.secret_id', 's.id'))
			->where($qb->expr()->eq('v.encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('s.owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('s.owner_id', $qb->createNamedParameter($ownerId)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countBySuiteForOwner()

	/**
	 * Count an owner's version rows on a suite whose owning secret carries NO
	 * recorded migration failure.
	 *
	 * A version has no `migration_error` column of its own — the column exists
	 * only on `keepiq_secrets` — so a failed version is recorded against the
	 * secret that owns it. That makes the owning secret's error the only
	 * available "accounted for" signal here.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return int
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function countUnaccountedBySuiteForOwner(
		string $encryptionSuiteId,
		string $ownerType,
		string $ownerId,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName(), 'v')
			->innerJoin('v', 'keepiq_secrets', 's', $qb->expr()->eq('v.secret_id', 's.id'))
			->where($qb->expr()->eq('v.encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('s.owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('s.owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->isNull('s.migration_error'));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countUnaccountedBySuiteForOwner()

	/**
	 * The owner's secret IDs that still have versions bound to a suite, paged.
	 *
	 * Version work is paged by SECRET rather than by version row because the
	 * re-encryption window ("head plus the N most recent") is defined per
	 * secret. Paging by version row could split one secret's history across
	 * two pages, and the window would then be computed against a partial
	 * group — re-encrypting the wrong versions and dropping survivors.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 * @param int $limit Maximum secret IDs
	 * @param int $offset Row offset
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function findSecretIdsWithSuiteVersionsForOwner(
		string $encryptionSuiteId,
		string $ownerType,
		string $ownerId,
		int $limit = 100,
		int $offset = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('v.secret_id')
			->from($this->getTableName(), 'v')
			->innerJoin('v', 'keepiq_secrets', 's', $qb->expr()->eq('v.secret_id', 's.id'))
			->where($qb->expr()->eq('v.encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('s.owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('s.owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('v.secret_id', 'ASC')
			->setMaxResults(max(1, $limit))
			->setFirstResult(max(0, $offset));

		$result = $qb->executeQuery();
		$ids = array_column($result->fetchAll(), 'secret_id');
		$result->closeCursor();

		return $ids;
	}//end findSecretIdsWithSuiteVersionsForOwner()
}//end class
