<?php

/**
 * Keepiq Migration Version 8
 *
 * Create the doriath_secrets table holding RSA-encrypted secret blobs and
 * plaintext searchable metadata.
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
 * Create the doriath_secrets table.
 */
class Version000008Date20260604000002 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the secrets table.
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

		if ($schema->hasTable('doriath_secrets') === true) {
			return $schema;
		}

		$table = $schema->createTable('doriath_secrets');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 2048]);
		$table->addColumn('type_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('folder_id', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('key', Types::TEXT, ['notnull' => true]);
		$table->addColumn('login', Types::TEXT, ['notnull' => false]);
		$table->addColumn('additional_fields', Types::TEXT, ['notnull' => false]);
		$table->addColumn('encryption_suite_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('owner_type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('possibly_compromised_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('migration_error', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['owner_type', 'owner_id'], 'doriath_sec_owner_idx');
		$table->addIndex(['folder_id'], 'doriath_sec_folder_idx');
		$table->addIndex(['encryption_suite_id'], 'doriath_sec_suite_idx');
		$table->addIndex(['type_id'], 'doriath_sec_type_idx');

		return $schema;
	}//end changeSchema()
}//end class
