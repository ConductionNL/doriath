<?php

/**
 * Keepiq Secret Mapper
 *
 * Database mapper for secret entities, including filtered/sorted/paginated
 * listing, plaintext-metadata search, and folder-cascade helpers.
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for Secret entities.
 *
 * @extends QBMapper<Secret>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Each method is a single,
 *   focused query the service layer composes (find/count/search/cascade);
 *   splitting the mapper would scatter the secrets table's access in one
 *   place across several classes for no benefit.
 */
class SecretMapper extends QBMapper {
	/**
	 * The columns a list may be sorted by (allow-list to prevent injection).
	 *
	 * @var string[]
	 */
	private const SORTABLE_COLUMNS = [
		'name',
		'url',
		'created_at',
		'updated_at',
	];

	/**
	 * Constructor for SecretMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_secrets', entityClass: Secret::class);
	}//end __construct()

	/**
	 * Find a secret by its UUID.
	 *
	 * @param string $id The secret ID
	 *
	 * @return Secret
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): Secret {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Resolve a sort column from the allow-list, defaulting to name.
	 *
	 * @param string|null $sort The requested sort column
	 *
	 * @return string A safe column name
	 */
	private function resolveSortColumn(?string $sort): string {
		if ($sort !== null && in_array($sort, self::SORTABLE_COLUMNS, true) === true) {
			return $sort;
		}

		return 'name';
	}//end resolveSortColumn()

	/**
	 * Find secrets owned by an owner, with optional folder filter, sort, and
	 * pagination.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 * @param string|null $folderId Filter by folder ID (null = no folder filter)
	 * @param string|null $sort Sort column (name, url, created_at, updated_at)
	 * @param string $direction Sort direction (asc/desc)
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 * @param string|null $typeId Filter by secret-type ID (null = all types)
	 *
	 * @return Secret[]
	 */
	public function findByOwner(
		string $ownerType,
		string $ownerId,
		?string $folderId = null,
		?string $sort = null,
		string $direction = 'asc',
		int $limit = 1000,
		int $offset = 0,
		?string $typeId = null,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

		if ($folderId !== null) {
			$qb->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
		}

		if ($typeId !== null) {
			$qb->andWhere($qb->expr()->eq('type_id', $qb->createNamedParameter($typeId)));
		}

		$dir = 'ASC';
		if (strtolower($direction) === 'desc') {
			$dir = 'DESC';
		}

		$qb->orderBy($this->resolveSortColumn(sort: $sort), $dir)
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities(query: $qb);
	}//end findByOwner()

	/**
	 * Page through ALL user-owned secrets (rotation-expiry-policies §4.1
	 * scan job). Ordered by id for stable pagination across runs.
	 *
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return Secret[]
	 */
	public function findAllUserOwnedPaged(int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->orderBy('id', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities(query: $qb);
	}//end findAllUserOwnedPaged()

	/**
	 * Find an owner's secrets by exact (case-sensitive) plaintext name,
	 * optionally constrained to a single folder.
	 *
	 * Returns every match — name uniqueness is not enforced in the data
	 * model, so the caller decides the ambiguity policy (the machine API
	 * returns 409 on more than one). The query is keyed by owner so it can
	 * never reach another vault.
	 *
	 * @param string $ownerType The owner type (e.g. 'application')
	 * @param string $ownerId The owner ID
	 * @param string $name The exact secret name to match
	 * @param string|null $folderId Restrict to this folder (null = whole vault)
	 *
	 * @return Secret[] Zero, one, or many matches (ordered by id for stability)
	 */
	public function findByName(
		string $ownerType,
		string $ownerId,
		string $name,
		?string $folderId = null,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));

		if ($folderId !== null) {
			$qb->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
		}

		$qb->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByName()

	/**
	 * Find an owner's secrets updated strictly after a given instant.
	 *
	 * Powers the machine API's `updated_since` rotation-polling query: a
	 * consumer passes its last-poll timestamp and receives only the
	 * secrets that changed since. Keyed by owner — cross-vault rows are
	 * structurally unreachable.
	 *
	 * @param string $ownerType The owner type (e.g. 'application')
	 * @param string $ownerId The owner ID
	 * @param DateTime $since Return secrets with updated_at later than this
	 * @param int $limit Maximum rows
	 *
	 * @return Secret[]
	 */
	public function findByOwnerUpdatedSince(
		string $ownerType,
		string $ownerId,
		DateTime $since,
		int $limit = 1000,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere(
				$qb->expr()->gt(
					'updated_at',
					$qb->createNamedParameter(
						$since->format('Y-m-d H:i:s'),
						IQueryBuilder::PARAM_STR
					)
				)
			)
			->orderBy('updated_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findByOwnerUpdatedSince()

	/**
	 * Count the secrets owned by an owner, with an optional folder filter.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 * @param string|null $folderId Filter by folder ID (null = no folder filter)
	 * @param string|null $typeId Filter by secret-type ID (null = all types)
	 *
	 * @return int
	 */
	public function countByOwner(
		string $ownerType,
		string $ownerId,
		?string $folderId = null,
		?string $typeId = null,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

		if ($folderId !== null) {
			$qb->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
		}

		if ($typeId !== null) {
			$qb->andWhere($qb->expr()->eq('type_id', $qb->createNamedParameter($typeId)));
		}

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countByOwner()

	/**
	 * Count secrets of a type, optionally only those whose expiry falls
	 * before a cutoff (certificate-lifecycle §2.6 issued-cert counts).
	 *
	 * @param string $typeId The secret type id
	 * @param \DateTime|null $expiresBefore Only rows with expires_at set and before this instant
	 *
	 * @return int
	 */
	public function countByTypeId(string $typeId, ?\DateTime $expiresBefore = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('type_id', $qb->createNamedParameter($typeId)));

		if ($expiresBefore !== null) {
			$qb->andWhere($qb->expr()->isNotNull('expires_at'))
				->andWhere($qb->expr()->lt('expires_at', $qb->createNamedParameter($expiresBefore, 'datetime')));
		}

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countByTypeId()

	/**
	 * Find the secrets directly contained in a folder (attachment cascade).
	 *
	 * @param string $folderId The folder ID
	 *
	 * @return Secret[]
	 */
	public function findByFolderId(string $folderId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

		return $this->findEntities(query: $qb);
	}//end findByFolderId()

	/**
	 * Count the secrets directly contained in a folder.
	 *
	 * @param string $folderId The folder ID
	 *
	 * @return int
	 */
	public function countByFolder(string $folderId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countByFolder()

	/**
	 * Count the secrets contained in any of the given folder IDs (used for
	 * recursive subtree counts where the subtree IDs are pre-collected).
	 *
	 * @param string[] $folderIds The folder IDs
	 *
	 * @return int
	 */
	public function countByFolderIds(array $folderIds): int {
		if ($folderIds === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where(
				$qb->expr()->in(
					'folder_id',
					$qb->createNamedParameter($folderIds, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countByFolderIds()

	/**
	 * Search a user's secrets by plaintext name or url substring match.
	 *
	 * Performs the SQL pre-filter stage of the two-stage fuzzy search. The
	 * Levenshtein post-filter is applied by the service over a bounded
	 * window. The result set is capped by `$limit` so this stage itself
	 * cannot return an unbounded row set regardless of vault size.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 * @param string $term The search term
	 * @param int $limit The maximum number of rows to return
	 *
	 * @return Secret[]
	 */
	public function searchByNameOrUrl(string $ownerType, string $ownerId, string $term, int $limit = 200): array {
		$like = '%' . $this->db->escapeLikeParameter($term) . '%';

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->iLike('name', $qb->createNamedParameter($like)),
					$qb->expr()->iLike('url', $qb->createNamedParameter($like))
				)
			)
			->setMaxResults(max(1, $limit));

		return $this->findEntities(query: $qb);
	}//end searchByNameOrUrl()

	/**
	 * Find secrets whose name or url matches a query, for the Nextcloud
	 * unified search provider. Scoped to a user, capped at a small limit.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $term The search term
	 * @param int $limit Maximum rows
	 *
	 * @return Secret[]
	 */
	public function findForUnifiedSearch(string $userId, string $term, int $limit): array {
		$like = '%' . $this->db->escapeLikeParameter($term) . '%';

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($userId)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->iLike('name', $qb->createNamedParameter($like)),
					$qb->expr()->iLike('url', $qb->createNamedParameter($like))
				)
			)
			->orderBy('name', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findForUnifiedSearch()

	/**
	 * Re-parent every secret in a folder to a new folder (or root when null).
	 *
	 * @param string $oldFolderId The current folder ID
	 * @param string|null $newFolderId The target folder ID (null = root)
	 *
	 * @return void
	 */
	public function updateFolderForSecrets(string $oldFolderId, ?string $newFolderId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('folder_id', $qb->createNamedParameter($newFolderId))
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($oldFolderId)));

		$qb->executeStatement();
	}//end updateFolderForSecrets()

	/**
	 * Delete every secret directly contained in a folder.
	 *
	 * @param string $folderId The folder ID
	 *
	 * @return void
	 */
	public function deleteByFolderId(string $folderId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

		$qb->executeStatement();
	}//end deleteByFolderId()

	/**
	 * Reassign every secret of a given type to a different type (used when a
	 * custom SecretType is deleted and its secrets fall back to login).
	 *
	 * @param string $oldTypeId The type ID being removed
	 * @param string $newTypeId The fallback type ID
	 *
	 * @return void
	 */
	public function reassignType(string $oldTypeId, string $newTypeId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('type_id', $qb->createNamedParameter($newTypeId))
			->where($qb->expr()->eq('type_id', $qb->createNamedParameter($oldTypeId)));

		$qb->executeStatement();
	}//end reassignType()

	/**
	 * Delete every secret owned by a user (account-deletion cascade).
	 *
	 * Idempotent: a second call simply matches no rows.
	 *
	 * @param string $ownerId The Nextcloud user ID
	 *
	 * @return int The number of rows deleted
	 *
	 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
	 */
	public function deleteByOwnerUser(string $ownerId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

		return $qb->executeStatement();
	}//end deleteByOwnerUser()

	/**
	 * Mark a recipient copy as a tombstoned, detached share-copy.
	 *
	 * Writes only display metadata (timestamp + non-personal reason token). The
	 * recipient retains full ownership and access; no personal data of the
	 * deleted sharer is written (secret-export-gdpr D4 step 2).
	 *
	 * @param string $secretId The recipient copy's Secret ID
	 * @param string $reason The non-personal tombstone reason token
	 *
	 * @return void
	 *
	 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
	 */
	public function tombstone(string $secretId, string $reason): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('tombstoned_at', $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')))
			->set('tombstone_reason', $qb->createNamedParameter($reason))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($secretId)));

		$qb->executeStatement();
	}//end tombstone()

	/**
	 * Reassign the owner of a single secret (delegation ownership transfer).
	 *
	 * @param string $secretId The Secret ID
	 * @param string $newOwnerId The delegate's Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
	 */
	public function reassignOwner(string $secretId, string $newOwnerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('owner_id', $qb->createNamedParameter($newOwnerId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($secretId)));

		$qb->executeStatement();
	}//end reassignOwner()

	/**
	 * Find every Secret encrypted under a given EncryptionSuite. Used by
	 * compromise-recovery listeners to fan over the freshly re-suited
	 * copies and surface the ones the migration flagged as possibly
	 * compromised.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 *
	 * @return Secret[]
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#8.4
	 */
	public function findByEncryptionSuiteId(string $encryptionSuiteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq(
					'encryption_suite_id',
					$qb->createNamedParameter($encryptionSuiteId)
				)
			);

		return $this->findEntities(query: $qb);
	}//end findByEncryptionSuiteId()

	/**
	 * Count an owner's secrets still bound to a suite.
	 *
	 * This is the derived-progress source for compromise-recovery migration:
	 * a secret still pointing at `old_suite_id` has not been migrated. There
	 * is deliberately no counter column — a count that can drift from the
	 * rows it describes would let a migration report itself complete while
	 * ciphertext is still sealed to the compromised key.
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
			->from($this->getTableName())
			->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countBySuiteForOwner()

	/**
	 * Count an owner's secrets still bound to a suite that carry NO recorded
	 * migration failure — the rows a migration has not yet accounted for.
	 *
	 * This is what the completion gate blocks on. A row with a recorded
	 * `migration_error` has been attempted and reported as unrecoverable, so it
	 * is a decision for the user rather than a reason to keep the migration
	 * open forever. A row with no error has simply not been reached — the
	 * migration is genuinely unfinished and must be resumed, not finalised,
	 * because finalising would lock the old suite and take every un-reached
	 * secret down with it.
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
			->from($this->getTableName())
			->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->isNull('migration_error'));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countUnaccountedBySuiteForOwner()

	/**
	 * List an owner's secrets left on a suite WITH a recorded migration failure.
	 *
	 * These are the rows that become unrecoverable when the migration is
	 * finalised, so the client is shown exactly this list — by name — before it
	 * acknowledges the loss.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 * @param int $limit Maximum rows
	 *
	 * @return Secret[]
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function findFailedBySuiteForOwner(
		string $encryptionSuiteId,
		string $ownerType,
		string $ownerId,
		int $limit = 500,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->isNotNull('migration_error'))
			->orderBy('name', 'ASC')
			->setMaxResults(max(1, $limit));

		return $this->findEntities(query: $qb);
	}//end findFailedBySuiteForOwner()

	/**
	 * List an owner's secrets still bound to a suite, paged.
	 *
	 * Ordered by `id` so paging is stable while the migration re-points rows
	 * out from under it: a migrated row leaves the result set entirely, so an
	 * unstable order would let the client skip rows between pages.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return Secret[]
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function findBySuiteForOwner(
		string $encryptionSuiteId,
		string $ownerType,
		string $ownerId,
		int $limit = 100,
		int $offset = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('id', 'ASC')
			->setMaxResults(max(1, $limit))
			->setFirstResult(max(0, $offset));

		return $this->findEntities(query: $qb);
	}//end findBySuiteForOwner()
}//end class
