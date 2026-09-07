<?php

/**
 * Keepiq Migration Version 9
 *
 * Create the doriath_share_targets table for user-to-user secret sharing.
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
 * Create the doriath_share_targets table.
 *
 * Smallest scaffold for the BLOCKED implement-user-sharing change. This
 * covers the SecretShare entity from task 1.1 (renamed to share_targets to
 * match the smallest-scaffold naming); GroupShare and SecretDelegation
 * tables are intentionally NOT created in this scaffold and are deferred
 * to the dedicated build cycle that ships the full sharing graph.
 */
class Version000009Date20260611000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the share_targets table.
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

		if ($schema->hasTable('doriath_share_targets') === true) {
			return $schema;
		}

		$table = $schema->createTable('doriath_share_targets');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('source_secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('target_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('group_share_id', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['source_secret_id'], 'doriath_st_source_idx');
		$table->addIndex(['target_user_id'], 'doriath_st_target_idx');
		$table->addIndex(['secret_id'], 'doriath_st_copy_idx');

		return $schema;
	}//end changeSchema()
}//end class
