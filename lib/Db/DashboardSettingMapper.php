<?php

/**
 * Keepiq Dashboard Setting Mapper
 *
 * Database mapper for per-user dashboard settings.
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
 * Mapper for DashboardSetting entities.
 *
 * @extends QBMapper<DashboardSetting>
 */
class DashboardSettingMapper extends QBMapper {
	/**
	 * Constructor for DashboardSettingMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_dashboard_settings', entityClass: DashboardSetting::class);
	}//end __construct()

	/**
	 * Find a dashboard setting by its UUID.
	 *
	 * @param string $id The UUID
	 *
	 * @return DashboardSetting
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): DashboardSetting {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find a single setting for a user by key.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $settingKey The setting key
	 *
	 * @return DashboardSetting
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByUserAndKey(string $userId, string $settingKey): DashboardSetting {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('setting_key', $qb->createNamedParameter($settingKey)));

		return $this->findEntity(query: $qb);
	}//end findByUserAndKey()

	/**
	 * Find all dashboard settings for a user.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return DashboardSetting[]
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntities(query: $qb);
	}//end findByUser()

	/**
	 * Delete all dashboard settings for a user (account deletion cascade).
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return void
	 */
	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}//end deleteByUser()
}//end class
