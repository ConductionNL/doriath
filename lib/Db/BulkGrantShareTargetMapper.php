<?php

/**
 * Keepiq Bulk-Grant Share Target Mapper
 *
 * Reads and cascades over `keepiq_share_targets` keyed on the BULK GRANT that
 * produced each row, rather than on the row's own identity.
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

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Provenance-keyed access to ShareTarget rows.
 *
 * WHY A SECOND MAPPER OVER ONE TABLE.
 *
 * A row in `keepiq_share_targets` is one recipient's encrypted copy of one
 * source secret, and it is reached in two quite different ways. Most callers
 * ask about the row itself — by id, by source secret, by target user, by
 * recipient secret copy. Four callers instead ask about the GRANT that created
 * a whole fan-out of rows: `group_share_id` and `team_folder_id` are foreign
 * keys to the bulk-grant records that the GroupShareService and the
 * TeamFolder* services own, and every query on them is a cascade — "which rows
 * did this grant produce, so they can be listed or revoked together".
 *
 * Those two responsibilities were both public API on `ShareTargetMapper`,
 * which is how it reached twelve public methods. Splitting on provenance keeps
 * each mapper's callers coherent (the bulk-grant services talk only to this
 * one; the per-target services never do) and is the only cut available that
 * does not merge two intentions behind a flag argument.
 *
 * The shared table name is deliberate and not a smell: both mappers project
 * the same entity, and QBMapper's contract is (connection, table, entity) —
 * nothing about it says one table may have only one mapper.
 *
 * @extends QBMapper<ShareTarget>
 */
class BulkGrantShareTargetMapper extends QBMapper {
	/**
	 * Constructor for BulkGrantShareTargetMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_share_targets', entityClass: ShareTarget::class);
	}//end __construct()

	/**
	 * Find all share targets that descend from a given group share.
	 *
	 * The returned set is the per-member fan-out the GroupShareService
	 * created when the source secret was shared with the group; revoking
	 * the GroupShare cascades through this lookup.
	 *
	 * @param string $groupShareId The group-share ID
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#2.2
	 */
	public function findByGroupShare(string $groupShareId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_share_id', $qb->createNamedParameter($groupShareId)));

		return $this->findEntities(query: $qb);
	}//end findByGroupShare()

	/**
	 * Delete every share target derived from a given group share (cascade).
	 *
	 * Pair with `deleteBySourceSecret` on the GroupShare cascade so the
	 * recipient's encrypted copies vanish together with the group-share
	 * row that created them.
	 *
	 * @param string $groupShareId The group-share ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#2.2
	 */
	public function deleteByGroupShare(string $groupShareId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('group_share_id', $qb->createNamedParameter($groupShareId)));

		$qb->executeStatement();
	}//end deleteByGroupShare()

	/**
	 * Find all share targets that descend from a given team folder.
	 *
	 * The returned set is the per-(secret x recipient) fan-out the
	 * TeamFolderService created; unsharing the folder or removing a
	 * member cascades through this lookup.
	 *
	 * @param string $teamFolderId The team-folder ID
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#1.3
	 */
	public function findByTeamFolder(string $teamFolderId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('team_folder_id', $qb->createNamedParameter($teamFolderId)));

		return $this->findEntities(query: $qb);
	}//end findByTeamFolder()

	/**
	 * Find the team-folder-derived share targets held by one recipient
	 * across a single team folder (leave/remove revocation scope).
	 *
	 * @param string $teamFolderId The team-folder ID
	 * @param string $targetUserId The recipient Nextcloud user ID
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#1.3
	 */
	public function findByTeamFolderAndTargetUser(string $teamFolderId, string $targetUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('team_folder_id', $qb->createNamedParameter($teamFolderId)))
			->andWhere($qb->expr()->eq('target_user_id', $qb->createNamedParameter($targetUserId)));

		return $this->findEntities(query: $qb);
	}//end findByTeamFolderAndTargetUser()
}//end class
