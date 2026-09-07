<?php

/**
 * Keepiq Migration Version 12
 *
 * Create the keepiq_applications table — backs the registered-application
 * scaffold for the BLOCKED implement-application-mgmt change.
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
 * Create the keepiq_applications table.
 *
 * Smallest scaffold for the BLOCKED implement-application-mgmt change.
 * Full JWT-Bearer auth + EncryptionSuite provisioning + cross-app
 * notification ship with the dedicated build cycle.
 */
class Version000012Date20260611000003 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the applications table.
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

		if ($schema->hasTable('keepiq_applications') === true) {
			return null;
		}

		$table = $schema->createTable('keepiq_applications');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'external']);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
		$table->addColumn('csr', Types::TEXT, ['notnull' => false]);
		$table->addColumn('registered_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('approved_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('approved_at', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['status'], 'keepiq_app_status_idx');
		$table->addIndex(['registered_by'], 'keepiq_app_registrant_idx');
		$table->addIndex(['name'], 'keepiq_app_name_idx');

		return $schema;
	}//end changeSchema()
}//end class
