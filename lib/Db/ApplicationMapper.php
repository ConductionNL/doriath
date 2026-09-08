<?php

/**
 * Keepiq Application Mapper
 *
 * Database mapper for registered application rows.
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
 * Mapper for Application entities.
 *
 * @extends QBMapper<Application>
 */
class ApplicationMapper extends QBMapper {
	/**
	 * Constructor for ApplicationMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_applications', entityClass: Application::class);
	}//end __construct()

	/**
	 * Find an application by its UUID.
	 *
	 * @param string $id The application ID
	 *
	 * @return Application
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): Application {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find all applications, optionally filtered by status.
	 *
	 * @param string|null $status Optional status filter
	 *
	 * @return Application[]
	 */
	public function findAll(?string $status = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		if ($status !== null) {
			$qb->where($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		$qb->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * Find all pending applications.
	 *
	 * @return Application[]
	 */
	public function findPending(): array {
		return $this->findAll(status: Application::STATUS_PENDING);
	}//end findPending()

	/**
	 * Count pending applications (used by the dashboard summary).
	 *
	 * @return int
	 */
	public function countPending(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Application::STATUS_PENDING)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countPending()

	/**
	 * Find all applications registered by a given Nextcloud user.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return Application[]
	 */
	public function findByRegistrant(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('registered_by', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByRegistrant()

	/**
	 * Find the active application matching a given name (if any).
	 *
	 * @param string $name The application name
	 *
	 * @return Application
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findActiveByName(string $name): Application {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->andWhere(
				$qb->expr()->eq('status', $qb->createNamedParameter(Application::STATUS_ACTIVE))
			);

		return $this->findEntity(query: $qb);
	}//end findActiveByName()

	/**
	 * Count all applications, optionally filtered by status.
	 *
	 * @param string|null $status Optional status filter
	 *
	 * @return int
	 */
	public function countAll(?string $status = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName());

		if ($status !== null) {
			$qb->where($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countAll()
}//end class
