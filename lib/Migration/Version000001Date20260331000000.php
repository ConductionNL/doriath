<?php

/**
 * Keepiq Migration Version 1
 *
 * Create the keepiq_enc_suites table.
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
 * Create the keepiq_enc_suites table.
 */
class Version000001Date20260331000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the encryption suites table.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return null|ISchemaWrapper
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) 109 lines of straight-line
	 *   addColumn()/addIndex() DDL for the app's initial schema. There is no
	 *   branching or duplicated logic to extract — the length is the width of the
	 *   tables being created — and this is the one migration every fresh install
	 *   must run, so it is deliberately left as a single auditable block.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// @var ISchemaWrapper $schema
		$schema = $schemaClosure();

		if ($schema->hasTable('keepiq_enc_suites') === true) {
			return null;
		}

		$table = $schema->createTable('keepiq_enc_suites');

		$table->addColumn(
			'id',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 36,
			]
		);
		$table->addColumn(
			'owner_type',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 20,
			]
		);
		$table->addColumn(
			'owner_id',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 64,
			]
		);
		$table->addColumn(
			'certificate',
			Types::TEXT,
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'private_key',
			Types::TEXT,
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'status',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 20,
				'default' => 'active',
			]
		);
		$table->addColumn(
			'revoked_at',
			Types::DATETIME,
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'revoked_reason',
			Types::STRING,
			[
				'notnull' => false,
				'length' => 255,
			]
		);
		$table->addColumn(
			'revoked_by',
			Types::STRING,
			[
				'notnull' => false,
				'length' => 64,
			]
		);
		$table->addColumn(
			'reinstated_at',
			Types::DATETIME,
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'reinstated_by',
			Types::STRING,
			[
				'notnull' => false,
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

		$table->setPrimaryKey(['id']);
		$table->addIndex(['owner_type', 'owner_id'], 'keepiq_es_owner_idx');

		return $schema;
	}//end changeSchema()
}//end class
