<?php

/**
 * Keepiq Migration Version 13
 *
 * Create the doriath_group_shares table — backs the group-share scaffold
 * for the BLOCKED implement-user-sharing change.
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
 * Create the doriath_group_shares table.
 *
 * Smallest scaffold for the BLOCKED implement-user-sharing change §1.2.
 * Backs the GroupShare entity that fans a shared secret out to all
 * members of a Nextcloud group. The encrypted per-recipient Secret
 * copies land in the existing doriath_share_targets table referencing
 * the group_share_id column.
 */
class Version000013Date20260611000004 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the group_shares table.
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

		if ($schema->hasTable('doriath_group_shares') === true) {
			return $schema;
		}

		$table = $schema->createTable('doriath_group_shares');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('group_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['secret_id', 'group_id'], 'doriath_gs_secret_group_idx');
		$table->addIndex(['group_id'], 'doriath_gs_group_idx');

		return $schema;
	}//end changeSchema()
}//end class
