<?php

/**
 * Keepiq Migration - Honey credentials
 *
 * Adds `keepiq_honey_flags` (decoy markers — deliberately a SIDE
 * table, never a column on keepiq_secrets, so a recipient/attacker
 * cannot distinguish a honey secret from its response shape) and
 * `keepiq_honey_alerts` (one row per raised tripwire alert) —
 * honey-credentials §1. No secret material ever reaches these tables.
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
 * Creates the honey flag + alert tables.
 */
class Version000030Date20260718220000 extends SimpleMigrationStep {
	/**
	 * Apply the schema changes.
	 *
	 * @param IOutput $output The migration output
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure
	 * @param array<string,mixed> $options The migration options
	 *
	 * @return ISchemaWrapper|null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('keepiq_honey_flags') === false) {
			$table = $schema->createTable('keepiq_honey_flags');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['secret_id'], 'keepiq_hf_secret');
			$table->addIndex(['owner_id'], 'keepiq_hf_owner');
		}

		if ($schema->hasTable('keepiq_honey_alerts') === false) {
			$table = $schema->createTable('keepiq_honey_alerts');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('honey_flag_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('accessor_type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('accessor_id', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('channel', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('ip', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('user_agent', Types::STRING, ['notnull' => false, 'length' => 512]);
			$table->addColumn('access_count', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$table->addColumn('accessed_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('acknowledged_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('acknowledged_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('snoozed_until', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['honey_flag_id'], 'keepiq_ha_flag');
			$table->addIndex(['secret_id'], 'keepiq_ha_secret');
			$table->addIndex(['acknowledged_at'], 'keepiq_ha_ack');
		}

		return $schema;
	}//end changeSchema()
}//end class
