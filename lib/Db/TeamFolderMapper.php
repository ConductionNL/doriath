<?php

/**
 * Keepiq Team Folder Mapper
 *
 * Query-builder mapper for TeamFolder rows.
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
 * Mapper for the keepiq_team_folders table.
 *
 * @template-extends QBMapper<TeamFolder>
 */
class TeamFolderMapper extends QBMapper {
	/**
	 * Constructor for TeamFolderMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_team_folders', entityClass: TeamFolder::class);
	}//end __construct()

	/**
	 * Find a team folder by its UUID.
	 *
	 * @param string $id The team-folder UUID
	 *
	 * @return TeamFolder
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): TeamFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find the team folder attached to a given Folder, if any.
	 *
	 * @param string $folderId The Folder UUID
	 *
	 * @return TeamFolder
	 *
	 * @throws DoesNotExistException When the folder is not shared
	 */
	public function findByFolder(string $folderId): TeamFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

		return $this->findEntity(query: $qb);
	}//end findByFolder()

	/**
	 * Find all team folders owned by a user.
	 *
	 * @param string $ownerId The owner's Nextcloud user ID
	 *
	 * @return TeamFolder[]
	 */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

		return $this->findEntities(query: $qb);
	}//end findByOwner()
}//end class
