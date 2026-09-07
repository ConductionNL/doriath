<?php

/**
 * Keepiq Migration Version 6
 *
 * Create the doriath_secret_types table for categorising secrets.
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
 * Create the doriath_secret_types table.
 */
class Version000006Date20260604000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the secret types table.
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

		if ($schema->hasTable('doriath_secret_types') === true) {
			return $schema;
		}

		$table = $schema->createTable('doriath_secret_types');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('owner_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['name'], 'doriath_st_name_idx');
		$table->addIndex(['scope', 'owner_id'], 'doriath_st_scope_idx');

		return $schema;
	}//end changeSchema()
}//end class
