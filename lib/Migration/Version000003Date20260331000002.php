<?php

/**
 * Keepiq Migration Version 3
 *
 * Create the doriath_suite_migr table.
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
 * Create the doriath_suite_migr table.
 */
class Version000003Date20260331000002 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the suite migrations table.
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

		if ($schema->hasTable('doriath_suite_migr') === true) {
			return $schema;
		}

		$table = $schema->createTable('doriath_suite_migr');

		$table->addColumn(
			'id',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 36,
			]
		);
		$table->addColumn(
			'old_suite_id',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 36,
			]
		);
		$table->addColumn(
			'new_suite_id',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 36,
			]
		);
		$table->addColumn(
			'status',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 30,
				'default' => 'in_progress',
			]
		);
		$table->addColumn(
			'started_at',
			Types::DATETIME,
			[
				'notnull' => true,
			]
		);
		$table->addColumn(
			'completed_at',
			Types::DATETIME,
			[
				'notnull' => false,
			]
		);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['old_suite_id'], 'doriath_sm_old_suite_idx');
		$table->addIndex(['new_suite_id'], 'doriath_sm_new_suite_idx');

		return $schema;
	}//end changeSchema()
}//end class
