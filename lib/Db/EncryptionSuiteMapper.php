<?php

/**
 * Keepiq Encryption Suite Mapper
 *
 * Database mapper for encryption suite entities.
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for EncryptionSuite entities.
 *
 * @extends QBMapper<EncryptionSuite>
 */
class EncryptionSuiteMapper extends QBMapper {
	/**
	 * Constructor for EncryptionSuiteMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'doriath_enc_suites', entityClass: EncryptionSuite::class);
	}//end __construct()

	/**
	 * Find an encryption suite by its ID.
	 *
	 * @param string $id The suite ID
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): EncryptionSuite {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find all encryption suites for a given owner, newest first.
	 *
	 * The ordering is load-bearing, not cosmetic. Three frontend call sites pick
	 * the session's suite with `suites.find(s => s.status === 'active')`
	 * (`src/store/modules/session.js:67` — the unlock path — plus `:128` and
	 * `src/store/modules/encryptionSuite.js:49`). Compromise recovery leaves the
	 * old suite `active` until the migration terminates, so during a migration
	 * two suites are active and an unordered result meant those call sites bound
	 * the session to whichever row the database returned first — in practice the
	 * OLDEST. A user resuming after an interrupted migration then logged in
	 * against the suite they were migrating AWAY from.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return EncryptionSuite[]
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function findByOwner(string $ownerType, string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByOwner()

	/**
	 * Find the owner's active encryption suite, newest first.
	 *
	 * Deliberately returns the most recent active suite rather than insisting on
	 * exactly one. A compromise-recovery migration legitimately has two active
	 * suites for its whole duration — the old one must stay readable so the
	 * browser can decrypt what it is migrating — and the newest is always the
	 * write target. This used to be `findEntity()` over an unbounded match,
	 * which threw `MultipleObjectsReturnedException` mid-migration;
	 * `SecretService::getActiveSuiteOrBlock` caught that and reported "No active
	 * encryption suite", so creating a secret during a migration failed with the
	 * one diagnosis that was certainly wrong — the user had two.
	 *
	 * Callers that need to know about a multi-active state should use
	 * countActiveByOwner rather than relying on an exception from here.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException When the owner has no active suite at all
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function findActiveByOwner(string $ownerType, string $ownerId): EncryptionSuite {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);

		return $this->findEntity(query: $qb);
	}//end findActiveByOwner()

	/**
	 * The newest active suite for each of several owners, in one query.
	 *
	 * The batch equivalent of findActiveByOwner(), and it has to reproduce
	 * that method's ORDERING, not just its filter. Compromise recovery leaves
	 * the old suite `active` until the migration terminates, so an owner can
	 * legitimately have TWO active rows; findActiveByOwner() sorts newest
	 * first and takes one. A plain `owner_id IN (...) AND status = 'active'`
	 * returns both and lets the database decide which the caller sees — in
	 * practice the oldest, which is the certificate the owner is migrating
	 * AWAY from. Encrypting to it would produce a copy the recipient cannot
	 * open. Hence the same sort here, with the first row per owner winning.
	 *
	 * Owners with no active suite are simply absent from the result; the
	 * caller decides what that means.
	 *
	 * @param string   $ownerType The owner type
	 * @param string[] $ownerIds  The owner IDs to look up
	 *
	 * @return array<string,EncryptionSuite> Newest active suite, keyed by owner ID
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
	 */
	public function findActiveByOwners(string $ownerType, array $ownerIds): array {
		if ($ownerIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere(
				$qb->expr()->in(
					'owner_id',
					$qb->createNamedParameter($ownerIds, IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');

		$found = [];
		foreach ($this->findEntities(query: $qb) as $suite) {
			// First row per owner wins: the sort above puts the newest first.
			$found[$suite->getOwnerId()] ??= $suite;
		}

		return $found;
	}//end findActiveByOwners()

	/**
	 * Count an owner's active encryption suites.
	 *
	 * More than one is normal for the duration of a compromise-recovery
	 * migration and abnormal outside it, so this is the signal callers use to
	 * tell "mid-rotation" from "corrupt state" instead of inferring it from a
	 * thrown exception.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return int
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function countActiveByOwner(string $ownerType, string $ownerId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countActiveByOwner()

	/**
	 * Find all active encryption suites.
	 *
	 * @return EncryptionSuite[]
	 */
	public function findAllActive(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('active')));

		return $this->findEntities(query: $qb);
	}//end findAllActive()

	/**
	 * Find all active encryption suites with limit and offset.
	 *
	 * @param int $limit The maximum number of results
	 * @param int $offset The offset for pagination
	 *
	 * @return EncryptionSuite[]
	 */
	public function findAllActiveWithLimit(int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities(query: $qb);
	}//end findAllActiveWithLimit()

	/**
	 * Count active suites of one owner type (certificate-lifecycle §2.6
	 * issued-cert counts).
	 *
	 * @param string $ownerType 'user' or 'application'
	 *
	 * @return int
	 */
	public function countActiveByOwnerType(string $ownerType): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countActiveByOwnerType()

	/**
	 * Delete every encryption suite owned by a user (account-deletion cascade).
	 *
	 * Removes the certificate AND the encrypted private-key blob. Idempotent.
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
}//end class
