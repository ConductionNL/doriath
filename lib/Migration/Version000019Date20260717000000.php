<?php

/**
 * Keepiq Migration Version 19
 *
 * Team folder sharing (team-folder-sharing §1.1/§1.2). Creates
 * `doriath_team_folders` (membership attachment on an existing owner
 * Folder) and `doriath_team_folder_members` (user/group member rows),
 * and adds a nullable indexed `team_folder_id` provenance column to the
 * existing share-target table, parallel to `group_share_id`. No key
 * material is stored on any of these tables (ADR-003).
 *
 * @category Migration
 * @package  OCA\Keepiq\Migration
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

namespace OCA\Keepiq\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the team-folder tables and the share provenance column.
 */
class Version000019Date20260717000000 extends SimpleMigrationStep {
	/**
	 * Create doriath_team_folders + doriath_team_folder_members and add
	 * team_folder_id to the share-target table.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// @var ISchemaWrapper $schema
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('doriath_team_folders') === false) {
			$table = $schema->createTable('doriath_team_folders');

			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('folder_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner_id'], 'doriath_tf_owner_idx');
			$table->addUniqueIndex(['folder_id'], 'doriath_tf_folder_uniq');
			$changed = true;
		}

		if ($schema->hasTable('doriath_team_folder_members') === false) {
			$table = $schema->createTable('doriath_team_folder_members');

			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('team_folder_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('member_type', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('member_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('added_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['team_folder_id'], 'doriath_tfm_folder_idx');
			$table->addIndex(['member_id'], 'doriath_tfm_member_idx');
			$table->addUniqueIndex(
				['team_folder_id', 'member_type', 'member_id'],
				'doriath_tfm_membership_uniq'
			);
			$changed = true;
		}

		if ($schema->hasTable('doriath_share_targets') === true) {
			$table = $schema->getTable('doriath_share_targets');
			if ($table->hasColumn('team_folder_id') === false) {
				$table->addColumn('team_folder_id', Types::STRING, ['notnull' => false, 'length' => 36]);
				$table->addIndex(['team_folder_id'], 'doriath_st_teamfolder_idx');
				$changed = true;
			}
		}

		if ($changed === false) {
			return $schema;
		}

		return $schema;
	}//end changeSchema()
}//end class
