<?php

/**
 * Keepiq Team Folder Member Mapper
 *
 * Query-builder mapper for TeamFolderMember rows. Revoke-on-leave needs
 * member lookup by member_id, hence the normalized table over a JSON
 * member blob.
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
 * Mapper for the keepiq_team_folder_members table.
 *
 * @template-extends QBMapper<TeamFolderMember>
 */
class TeamFolderMemberMapper extends QBMapper {
	/**
	 * Constructor for TeamFolderMemberMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'keepiq_team_folder_members',
			entityClass: TeamFolderMember::class
		);
	}//end __construct()

	/**
	 * Find a member row by its UUID.
	 *
	 * @param string $id The member-row UUID
	 *
	 * @return TeamFolderMember
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): TeamFolderMember {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find all member rows of a team folder.
	 *
	 * @param string $teamFolderId The team-folder UUID
	 *
	 * @return TeamFolderMember[]
	 */
	public function findByTeamFolder(string $teamFolderId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('team_folder_id', $qb->createNamedParameter($teamFolderId)));

		return $this->findEntities(query: $qb);
	}//end findByTeamFolder()

	/**
	 * Find one membership row by (team folder, type, member).
	 *
	 * @param string $teamFolderId The team-folder UUID
	 * @param string $memberType The member type (`user`|`group`)
	 * @param string $memberId The user or group ID
	 *
	 * @return TeamFolderMember
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findMembership(string $teamFolderId, string $memberType, string $memberId): TeamFolderMember {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('team_folder_id', $qb->createNamedParameter($teamFolderId)))
			->andWhere($qb->expr()->eq('member_type', $qb->createNamedParameter($memberType)))
			->andWhere($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId)));

		return $this->findEntity(query: $qb);
	}//end findMembership()

	/**
	 * Find all group-type memberships for a given group ID (leave/join
	 * propagation looks up which team folders a group belongs to).
	 *
	 * @param string $groupId The Nextcloud group ID
	 *
	 * @return TeamFolderMember[]
	 */
	public function findGroupMemberships(string $groupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_type', $qb->createNamedParameter('group')))
			->andWhere($qb->expr()->eq('member_id', $qb->createNamedParameter($groupId)));

		return $this->findEntities(query: $qb);
	}//end findGroupMemberships()

	/**
	 * Find all direct user-type memberships for a given user ID.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return TeamFolderMember[]
	 */
	public function findUserMemberships(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('member_id', $qb->createNamedParameter($userId)));

		return $this->findEntities(query: $qb);
	}//end findUserMemberships()

	/**
	 * Delete every member row of a team folder (unshare cascade).
	 *
	 * @param string $teamFolderId The team-folder UUID
	 *
	 * @return void
	 */
	public function deleteByTeamFolder(string $teamFolderId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('team_folder_id', $qb->createNamedParameter($teamFolderId)));
		$qb->executeStatement();
	}//end deleteByTeamFolder()
}//end class
