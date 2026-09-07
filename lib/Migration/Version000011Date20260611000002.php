<?php

/**
 * Keepiq Migration Version 11
 *
 * Create the keepiq_dashboard_settings table — backs the per-user
 * dashboard preference scaffold for the BLOCKED
 * implement-dashboard-settings change.
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
 * Create the keepiq_dashboard_settings table.
 *
 * Smallest scaffold for the BLOCKED implement-dashboard-settings change.
 * The full DashboardSummary endpoint + admin/user settings split +
 * dashboard widgets ship with the dedicated build cycle.
 */
class Version000011Date20260611000002 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the dashboard_settings table.
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

		if ($schema->hasTable('keepiq_dashboard_settings') === true) {
			return null;
		}

		$table = $schema->createTable('keepiq_dashboard_settings');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('setting_key', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('setting_value', Types::TEXT, ['notnull' => true]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['user_id', 'setting_key'], 'keepiq_ds_user_key_uniq');
		$table->addIndex(['user_id'], 'keepiq_ds_user_idx');

		return $schema;
	}//end changeSchema()
}//end class
