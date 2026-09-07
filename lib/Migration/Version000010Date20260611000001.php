<?php

/**
 * Keepiq Migration Version 10
 *
 * Create the doriath_secret_requests table — backs the two-phase public
 * fill-in flow for secret requests.
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
 * Create the doriath_secret_requests table.
 *
 * Smallest scaffold for the BLOCKED implement-secret-requests change.
 * Full re-request flow + compromise-locking + public fill-in endpoint
 * + Vue UI ship with the dedicated build cycle.
 */
class Version000010Date20260611000001 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the secret_requests table.
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

		if ($schema->hasTable('doriath_secret_requests') === true) {
			return $schema;
		}

		$table = $schema->createTable('doriath_secret_requests');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('encryption_suite_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('requested_fields', Types::TEXT, ['notnull' => true]);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
		// NC 32's MigrationService forbids a NOT NULL BOOLEAN column (it cannot
		// safely store 0/false), so the flag is nullable with a false default.
		$table->addColumn('is_re_request', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('fulfilled_at', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token'], 'doriath_sr_token_uniq');
		$table->addIndex(['secret_id'], 'doriath_sr_secret_idx');
		$table->addIndex(['created_by'], 'doriath_sr_creator_idx');
		$table->addIndex(['encryption_suite_id'], 'doriath_sr_suite_idx');

		return $schema;
	}//end changeSchema()
}//end class
