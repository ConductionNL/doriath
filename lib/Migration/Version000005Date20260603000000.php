<?php

/**
 * Keepiq Migration Version 5
 *
 * Create the doriath_link_shares table for password-protected link
 * sharing of secrets to external parties.
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
 * Create the doriath_link_shares table.
 */
class Version000005Date20260603000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the link shares table.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return null|ISchemaWrapper
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Column definitions are inherently verbose.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// @var ISchemaWrapper $schema
		$schema = $schemaClosure();

		if ($schema->hasTable('doriath_link_shares') === true) {
			return $schema;
		}

		$table = $schema->createTable('doriath_link_shares');

		$table->addColumn(
			'id',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 36,
			]
		);
		$table->addColumn(
			'secret_id',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 36,
			]
		);
		$table->addColumn(
			'token',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 64,
			]
		);
		$table->addColumn(
			'encrypted_secret_snapshot',
			Types::TEXT,
			[
				'notnull' => true,
			]
		);
		$table->addColumn(
			'argon2id_salt',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 64,
			]
		);
		$table->addColumn(
			'encryption_suite_id',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 36,
			]
		);
		$table->addColumn(
			'usage_limit',
			Types::INTEGER,
			[
				'notnull' => true,
				'default' => 1,
			]
		);
		$table->addColumn(
			'usage_count',
			Types::INTEGER,
			[
				'notnull' => true,
				'default' => 0,
			]
		);
		$table->addColumn(
			'failed_attempts',
			Types::INTEGER,
			[
				'notnull' => true,
				'default' => 0,
			]
		);
		$table->addColumn(
			'created_by',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 64,
			]
		);
		$table->addColumn(
			'created_at',
			Types::DATETIME,
			[
				'notnull' => true,
			]
		);
		$table->addColumn(
			'expires_at',
			Types::DATETIME,
			[
				'notnull' => false,
			]
		);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token'], 'doriath_ls_token_idx');
		$table->addIndex(['secret_id'], 'doriath_ls_secret_idx');
		$table->addIndex(['created_by'], 'doriath_ls_creator_idx');

		return $schema;
	}//end changeSchema()
}//end class
